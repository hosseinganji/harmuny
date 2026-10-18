<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Models\Setting;

/**
 * Drains the bot's pending updates: new posts in a connected channel or group become tracks right away,
 * the chats the bot is added to are remembered (so private groups can be picked without an @username),
 * and a private /start marks the chat the bot may use as scratch space.
 */
class BotUpdates
{
    /** Chats the bot is a member of, keyed by id, for the "choose" list in Settings → Telegram. */
    public const CHATS_KEY = 'telegram.bot_chats';

    public function __construct(private BotClient $bot) {}

    /** @return array<int, int> highest message id seen per chat id */
    public function poll(): array
    {
        $offset = (int) Setting::get('telegram.update_offset', 0);
        $highest = [];

        do {
            try {
                $updates = $this->bot->call('getUpdates', [
                    'offset' => $offset,
                    'timeout' => 0,
                    'limit' => 100,
                    'allowed_updates' => ['message', 'edited_message', 'channel_post', 'edited_channel_post', 'my_chat_member'],
                ]);
            } catch (BotApiException $e) {
                if ($e->errorCode === 409) {
                    throw new BotApiException('This bot has a webhook set by another app, so updates go there instead. Press “I sent /start” or save the token again to remove it.', 409);
                }
                throw $e;
            }

            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;

                if ($member = $update['my_chat_member'] ?? null) {
                    $this->rememberMembership($member);

                    continue;
                }

                $post = $update['channel_post'] ?? $update['edited_channel_post'] ?? $update['message'] ?? $update['edited_message'] ?? null;
                if (! $post) {
                    continue;
                }
                $type = $post['chat']['type'] ?? null;
                if ($type === 'private') {
                    $this->handlePrivateMessage($post);

                    continue;
                }

                $chatId = (int) $post['chat']['id'];
                $highest[$chatId] = max($highest[$chatId] ?? 0, (int) $post['message_id']);
                $this->rememberChat($post['chat']);

                // A basic group that grows into a supergroup gets a new id and restarts its message ids.
                if (isset($post['migrate_to_chat_id'])) {
                    $this->migrate($chatId, (int) $post['migrate_to_chat_id']);

                    continue;
                }

                $this->record($chatId, $post);
            }

            Setting::put('telegram.update_offset', $offset);
        } while (count($updates) === 100);

        return $highest;
    }

    /** Add an audio post from a connected, enabled channel or group to the library. */
    private function record(int $chatId, array $post): void
    {
        $channel = Channel::query()->where('telegram_id', $chatId)->where('enabled', true)->first();
        $data = $channel ? BotMessageParser::parse($post, (int) $post['message_id']) : null;
        if ($data && $data['file_size'] <= config('music.bot.max_file_bytes')) {
            TrackUpserter::upsert($channel, $data);
        }
    }

    private function migrate(int $from, int $to): void
    {
        Channel::query()->where('telegram_id', $from)->update(['telegram_id' => $to, 'kind' => 'supergroup', 'last_message_id' => 0]);
        $chats = Setting::get(self::CHATS_KEY, []);
        if (isset($chats[$from])) {
            $chats[$to] = ['id' => $to, 'type' => 'supergroup'] + $chats[$from];
            unset($chats[$from]);
            Setting::put(self::CHATS_KEY, $chats);
        }
    }

    /** The bot was added to, promoted in, or removed from a chat. */
    private function rememberMembership(array $member): void
    {
        $chat = $member['chat'] ?? [];
        if (($chat['type'] ?? 'private') === 'private') {
            return;
        }
        if (in_array($member['new_chat_member']['status'] ?? null, ['left', 'kicked'], true)) {
            $chats = Setting::get(self::CHATS_KEY, []);
            unset($chats[$chat['id']]);
            Setting::put(self::CHATS_KEY, $chats);

            return;
        }
        $this->rememberChat($chat);
    }

    private function rememberChat(array $chat): void
    {
        $chats = Setting::get(self::CHATS_KEY, []);
        $entry = [
            'id' => (int) $chat['id'],
            'title' => $chat['title'] ?? (string) $chat['id'],
            'username' => $chat['username'] ?? null,
            'type' => $chat['type'] ?? 'group',
        ];
        if (($chats[$entry['id']] ?? null) !== $entry) {
            $chats[$entry['id']] = $entry;
            Setting::put(self::CHATS_KEY, $chats);
        }
    }

    private function handlePrivateMessage(array $message): void
    {
        if (! str_starts_with((string) ($message['text'] ?? ''), '/start')) {
            return;
        }

        Setting::put('telegram.dump_chat_id', (int) $message['chat']['id']);
        Setting::put('telegram.dump_chat_name', trim(($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')));

        try {
            $this->bot->call('sendMessage', [
                'chat_id' => $message['chat']['id'],
                'text' => "✅ Connected to Harmony.\n\nWhile syncing, channel and group posts briefly appear here and are deleted right away. Keep this chat; deleting it stops history syncing.",
            ]);
        } catch (BotApiException) {
            // Not important if the confirmation fails.
        }
    }
}
