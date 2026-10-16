<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Models\Track;
use Illuminate\Support\Facades\Log;

class ChannelSyncer
{
    public function __construct(private TelegramManager $telegram) {}

    /**
     * Pull audio messages from the channel into the tracks table.
     *
     * Incremental syncs only fetch messages newer than the last seen one.
     * A full sync walks the whole history, refreshes edited captions and
     * hides tracks whose messages were deleted from the channel.
     */
    public function sync(Channel $channel, bool $full = false): int
    {
        $channel->update(['sync_status' => 'syncing', 'sync_error' => null, 'sync_progress' => 0]);

        try {
            if (! $channel->telegram_id) {
                $info = $this->telegram->resolveChannel($channel->peer);
                $channel->update(['telegram_id' => $info['id'], 'title' => $info['title'], 'username' => $info['username']]);
            }

            $peer = $channel->inputPeer();
            $minId = $full ? 0 : (int) $channel->last_message_id;
            $offsetId = 0;
            $maxId = (int) $channel->last_message_id;
            $seen = [];
            $processed = 0;

            do {
                $page = $this->telegram->searchMusic($peer, $offsetId, $minId);
                foreach ($page['messages'] as $message) {
                    $this->upsert($channel, $message);
                    $seen[] = (int) $message['id'];
                    $maxId = max($maxId, (int) $message['id']);
                    $offsetId = (int) $message['id'];
                }
                $processed += count($page['messages']);
                $channel->update(['sync_progress' => $processed]);
                if ($page['messages']) {
                    usleep(300_000); // Stay well below Telegram's flood limits.
                }
            } while ($page['messages'] !== []);

            if ($full) {
                Track::query()
                    ->where('channel_id', $channel->id)
                    ->whereNotIn('message_id', $seen ?: [0])
                    ->delete();
            }

            $channel->update([
                'sync_status' => 'idle',
                'last_message_id' => $maxId,
                'last_synced_at' => now(),
            ]);

            return $processed;
        } catch (\Throwable $e) {
            Log::error('Channel sync failed', ['channel' => $channel->id, 'error' => $e->getMessage()]);
            $channel->update(['sync_status' => 'error', 'sync_error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }
    }

    private function upsert(Channel $channel, array $message): void
    {
        TrackUpserter::upsert($channel, MessageParser::parse($message));
    }
}
