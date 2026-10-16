<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Models\Setting;

/**
 * Drains the bot's pending updates: new channel posts become tracks right
 * away, and a private /start marks the chat the bot may use as scratch space.
 */
class BotUpdates
{
    public function __construct(private BotClient $bot) {}

    /** @return array<int, int> highest message id seen per chat id */
    public function poll(): array
    {
        $offset = (int) Setting::get('telegram.update_offset', 0);
        $highest = [];
        $maxBytes = config('music.bot.max_file_bytes');

        do {
            try {
                $updates = $this->bot->call('getUpdates', [
                    'offset' => $offset,
                    'timeout' => 0,
                    'limit' => 100,
                    'allowed_updates' => ['message', 'channel_post', 'edited_channel_post'],
                ]);
            } catch (BotApiException $e) {
                if ($e->errorCode === 409) {
                    throw new BotApiException('This bot has a webhook set by another app, so updates go there instead. Press “I sent /start” or save the token again to remove it.', 409);
                }
                throw $e;
            }

            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;

                if ($post = $update['channel_post'] ?? $update['edited_channel_post'] ?? null) {
                    $chatId = (int) $post['chat']['id'];
                    $highest[$chatId] = max($highest[$chatId] ?? 0, (int) $post['message_id']);

                    $channel = Channel::query()->where('telegram_id', $chatId)->where('enabled', true)->first();
                    $data = $channel ? BotMessageParser::parse($post, (int) $post['message_id']) : null;
                    if ($data && $data['file_size'] <= $maxBytes) {
                        TrackUpserter::upsert($channel, $data);
                    }
                } elseif ($message = $update['message'] ?? null) {
                    $this->handlePrivateMessage($message);
                }
            }

            Setting::put('telegram.update_offset', $offset);
        } while (count($updates) === 100);

        return $highest;
    }

    private function handlePrivateMessage(array $message): void
    {
        if (($message['chat']['type'] ?? null) !== 'private' || ! str_starts_with((string) ($message['text'] ?? ''), '/start')) {
            return;
        }

        Setting::put('telegram.dump_chat_id', (int) $message['chat']['id']);
        Setting::put('telegram.dump_chat_name', trim(($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')));

        try {
            $this->bot->call('sendMessage', [
                'chat_id' => $message['chat']['id'],
                'text' => "✅ Connected to Smart Music.\n\nWhile syncing, channel posts briefly appear here and are deleted right away. Keep this chat; deleting it stops history syncing.",
            ]);
        } catch (BotApiException) {
            // Not important if the confirmation fails.
        }
    }
}
