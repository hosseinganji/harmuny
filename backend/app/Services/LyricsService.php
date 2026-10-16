<?php

namespace App\Services;

use App\Models\Lyric;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Looks up lyrics on LRCLIB (free, synced LRC lyrics) and caches the result, including misses. */
class LyricsService
{
    public function find(string $title, ?string $artist, ?string $album = null, int $duration = 0): ?Lyric
    {
        $title = $this->cleanTitle($title);
        $key = md5(TextNormalizer::normalize($artist).'|'.TextNormalizer::normalize($title).'|'.intdiv($duration, 5));

        $cached = Lyric::query()->where('lookup_key', $key)->first();
        // Retry misses weekly, since LRCLIB grows over time.
        if ($cached && ($cached->source !== 'none' || $cached->updated_at->gt(now()->subWeek()))) {
            return $cached->source === 'none' ? null : $cached;
        }

        if (! config('music.lyrics.enabled')) {
            return null;
        }

        $hit = $this->fetch($title, $artist, $album, $duration);

        $lyric = Lyric::query()->updateOrCreate(['lookup_key' => $key], [
            'synced' => $hit['syncedLyrics'] ?? null,
            'plain' => $hit['plainLyrics'] ?? null,
            'instrumental' => (bool) ($hit['instrumental'] ?? false),
            'source' => $hit ? 'lrclib' : 'none',
        ]);
        $lyric->touch();

        return $hit ? $lyric : null;
    }

    private function fetch(string $title, ?string $artist, ?string $album, int $duration): ?array
    {
        $http = Http::baseUrl(config('music.lyrics.lrclib_url'))
            ->withUserAgent('SmartMusicPlayer/1.0 (self-hosted)')
            ->timeout(8)
            ->retry(1, 300, throw: false);

        try {
            if ($artist) {
                $exact = $http->get('/api/get', array_filter([
                    'track_name' => $title,
                    'artist_name' => $artist,
                    'album_name' => $album,
                    'duration' => $duration ?: null,
                ]));
                if ($exact->successful() && $this->hasLyrics($exact->json())) {
                    return $exact->json();
                }
            }

            $search = $http->get('/api/search', $artist
                ? ['track_name' => $title, 'artist_name' => $artist]
                : ['q' => $title]);
            if (! $search->successful()) {
                return null;
            }

            return collect($search->json() ?? [])
                ->filter(fn ($r) => $this->hasLyrics($r))
                ->filter(fn ($r) => ! $duration || abs(($r['duration'] ?? 0) - $duration) <= 8)
                // Prefer synced lyrics, then the closest duration.
                ->sortBy(fn ($r) => (empty($r['syncedLyrics']) ? 100_000 : 0) + abs(($r['duration'] ?? 0) - $duration))
                ->first();
        } catch (\Throwable $e) {
            Log::info('Lyrics lookup failed', ['title' => $title, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function hasLyrics(?array $result): bool
    {
        return $result && (! empty($result['syncedLyrics']) || ! empty($result['plainLyrics']) || ! empty($result['instrumental']));
    }

    /** Strip decorations like "(Official Video)", "[320]" or "feat. X" that defeat exact matching. */
    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/[\(\[][^\)\]]*(official|video|audio|lyric|remaster|320|128|kbps|hq|hd)[^\)\]]*[\)\]]/iu', '', $title);
        $title = preg_replace('/\s+(feat\.?|ft\.?|featuring)\s+.*$/iu', '', $title);

        return trim($title) ?: $title;
    }
}
