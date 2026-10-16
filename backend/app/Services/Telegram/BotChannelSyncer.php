<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Track;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Syncs a channel through a bot that is an admin of it.
 *
 * The Bot API cannot list a channel's history, so the bot walks message ids
 * one by one: it forwards each post into its private scratch chat to read it,
 * records audio, and deletes the copy at once. New posts also arrive as
 * updates. The scan stops after a run of ids that do not exist.
 */
class BotChannelSyncer
{
    /** Incremental runs only look a little past the last post; updates cover the rest. */
    private const INCREMENTAL_GAP = 20;

    public function __construct(private BotClient $bot, private BotUpdates $updates) {}

    public function sync(Channel $channel, bool $full = false): int
    {
        $channel->update(['sync_status' => 'syncing', 'sync_error' => null, 'sync_progress' => 0]);

        try {
            $dumpChat = Setting::get('telegram.dump_chat_id');
            if (! $dumpChat) {
                throw new RuntimeException('Send /start to the bot from your Telegram account first.');
            }

            if (! $channel->telegram_id) {
                $info = $this->resolve($channel->peer);
                $channel->update(['telegram_id' => $info['id'], 'title' => $info['title'], 'username' => $info['username']]);
            }
            $this->assertAdmin((int) $channel->telegram_id);

            $fromUpdates = $this->updates->poll()[(int) $channel->telegram_id] ?? 0;
            $knownMax = max((int) $channel->last_message_id, $fromUpdates);
            $firstRun = (int) $channel->last_message_id === 0;
            $gap = $full || $firstRun ? config('music.bot.scan_gap') : self::INCREMENTAL_GAP;
            $maxBytes = config('music.bot.max_file_bytes');

            $id = $full ? 1 : (int) $channel->last_message_id + 1;
            $lastSeen = $full ? 0 : (int) $channel->last_message_id;
            $misses = 0;
            $found = 0;
            $skipped = $full ? 0 : (int) $channel->skipped_count;
            $unforwardable = 0;
            $seen = [];

            while ($misses < $gap || $id <= $knownMax) {
                try {
                    $copy = $this->bot->call('forwardMessage', [
                        'chat_id' => $dumpChat,
                        'from_chat_id' => $channel->telegram_id,
                        'message_id' => $id,
                        'disable_notification' => true,
                    ]);
                } catch (BotApiException $e) {
                    if ($e->isMissingMessage()) {
                        $misses++;
                        $id++;

                        continue;
                    }
                    if (preg_match("/can't be forwarded|CHAT_FORWARDS_RESTRICTED/i", $e->getMessage())) {
                        // Service messages can't be forwarded; a protected channel refuses every post.
                        $unforwardable++;
                        if ($unforwardable >= 10 && $found === 0 && $seen === []) {
                            throw new RuntimeException('This channel blocks forwarding ("Restrict saving content" is on). Turn it off in the channel settings to sync with a bot.');
                        }
                        $misses = 0;
                        $lastSeen = $id++;

                        continue;
                    }
                    throw $e;
                }

                try {
                    $this->bot->call('deleteMessage', ['chat_id' => $dumpChat, 'message_id' => $copy['message_id']]);
                } catch (BotApiException) {
                    // A leftover copy in the scratch chat is harmless.
                }

                $misses = 0;
                $lastSeen = $id;
                $seen[] = $id;

                $data = BotMessageParser::parse($copy, $id);
                if ($data && $data['file_size'] > $maxBytes) {
                    $skipped++;
                } elseif ($data) {
                    TrackUpserter::upsert($channel, $data);
                    $found++;
                }

                if ($id % 10 === 0) {
                    $channel->update(['sync_status' => 'syncing', 'sync_progress' => $found, 'skipped_count' => $skipped]);
                }
                $id++;
            }

            if ($full) {
                // Posts that no longer exist were deleted from the channel.
                Track::query()
                    ->where('channel_id', $channel->id)
                    ->where('message_id', '<=', $lastSeen)
                    ->whereNotIn('message_id', $seen ?: [0])
                    ->delete();
            }

            $channel->update([
                'sync_status' => 'idle',
                'sync_progress' => $found,
                'skipped_count' => $skipped,
                'last_message_id' => $lastSeen,
                'last_synced_at' => now(),
            ]);

            return $found;
        } catch (\Throwable $e) {
            Log::error('Bot channel sync failed', ['channel' => $channel->id, 'error' => $e->getMessage()]);
            $channel->update(['sync_status' => 'error', 'sync_error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }
    }

    /** @return array{id:int,title:string,username:?string} */
    public function resolve(string $peer): array
    {
        try {
            $chat = $this->bot->call('getChat', ['chat_id' => $peer]);
        } catch (BotApiException $e) {
            throw new RuntimeException('The bot cannot see this channel. Add the bot to the channel as an admin first. ('.$e->getMessage().')');
        }
        if (($chat['type'] ?? null) !== 'channel') {
            throw new RuntimeException('This is not a channel.');
        }

        return ['id' => (int) $chat['id'], 'title' => $chat['title'] ?? $peer, 'username' => $chat['username'] ?? null];
    }

    public function assertAdmin(int $chatId): void
    {
        $me = $this->bot->getMe();
        try {
            $member = $this->bot->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $me['id']]);
        } catch (BotApiException $e) {
            throw new RuntimeException('The bot is not a member of this channel. Add it as an admin. ('.$e->getMessage().')');
        }
        if (! in_array($member['status'] ?? null, ['administrator', 'creator'], true)) {
            throw new RuntimeException('The bot must be an admin of the channel.');
        }
    }
}
