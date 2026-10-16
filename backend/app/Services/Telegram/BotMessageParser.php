<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

/** Turns a Bot API message (a channel post or a forwarded copy of one) into track attributes. */
class BotMessageParser
{
    /** The audio payload of a message: an audio file, or a document that is audio. */
    public static function audioOf(array $message): ?array
    {
        if (isset($message['audio'])) {
            return $message['audio'];
        }
        $document = $message['document'] ?? null;
        if ($document && str_starts_with((string) ($document['mime_type'] ?? ''), 'audio/')) {
            return $document;
        }

        return null;
    }

    /** @param int $messageId the post's id in the channel (forwarded copies have their own id) */
    public static function parse(array $message, int $messageId): ?array
    {
        $audio = self::audioOf($message);
        if (! $audio) {
            return null;
        }

        $fileName = $audio['file_name'] ?? null;
        $title = MessageParser::clean($audio['title'] ?? null);
        $artist = MessageParser::clean($audio['performer'] ?? null);
        if (! $title && $fileName) {
            [$title, $guessArtist] = MessageParser::guessFromFileName($fileName);
            $artist ??= $guessArtist;
        }

        // A forwarded copy keeps the original post time in forward_origin.
        $date = $message['forward_origin']['date'] ?? $message['forward_date'] ?? $message['date'] ?? time();
        $thumb = $audio['thumbnail'] ?? $audio['thumb'] ?? null;

        return [
            'message_id' => $messageId,
            'file_id' => $audio['file_id'],
            'thumb_file_id' => $thumb['file_id'] ?? null,
            'title' => $title ?: 'Untitled',
            'artist' => $artist,
            'duration' => (int) ($audio['duration'] ?? 0),
            'file_name' => $fileName,
            'mime_type' => $audio['mime_type'] ?? null,
            'file_size' => (int) ($audio['file_size'] ?? 0),
            'caption' => MessageParser::clean($message['caption'] ?? null),
            'has_cover' => $thumb !== null,
            'posted_at' => CarbonImmutable::createFromTimestamp($date),
        ];
    }
}
