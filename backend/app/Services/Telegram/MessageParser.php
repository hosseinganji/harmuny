<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

/** Turns a raw Telegram audio message into track attributes. */
class MessageParser
{
    public static function parse(array $message): array
    {
        $document = $message['media']['document'];
        $audio = [];
        $fileName = null;

        foreach ($document['attributes'] ?? [] as $attribute) {
            match ($attribute['_'] ?? null) {
                'documentAttributeAudio' => $audio = $attribute,
                'documentAttributeFilename' => $fileName = $attribute['file_name'],
                default => null,
            };
        }

        $title = self::clean($audio['title'] ?? null);
        $artist = self::clean($audio['performer'] ?? null);

        if (! $title && $fileName) {
            [$title, $guessArtist] = self::guessFromFileName($fileName);
            $artist ??= $guessArtist;
        }

        $thumbs = array_filter(
            $document['thumbs'] ?? [],
            fn ($t) => in_array($t['_'] ?? '', ['photoSize', 'photoSizeProgressive'], true)
        );

        return [
            'message_id' => (int) $message['id'],
            'title' => $title ?: 'Untitled',
            'artist' => $artist,
            'duration' => (int) ($audio['duration'] ?? 0),
            'file_name' => $fileName,
            'mime_type' => $document['mime_type'] ?? null,
            'file_size' => (int) ($document['size'] ?? 0),
            'caption' => self::clean($message['message'] ?? null),
            'has_cover' => $thumbs !== [],
            'posted_at' => CarbonImmutable::createFromTimestamp($message['date'] ?? time()),
        ];
    }

    /**
     * Many channel uploads only carry "Artist - Title.mp3" in the file name.
     *
     * @return array{0: ?string, 1: ?string} [title, artist]
     */
    public static function guessFromFileName(string $fileName): array
    {
        $base = preg_replace('/[_]+/', ' ', pathinfo($fileName, PATHINFO_FILENAME));
        if (str_contains($base, ' - ')) {
            [$artist, $title] = array_map('trim', explode(' - ', $base, 2));

            return [self::clean($title), self::clean($artist)];
        }

        return [self::clean($base), null];
    }

    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, 250);
    }
}
