<?php

namespace App\Services;

use App\Models\Track;
use App\Services\Telegram\BotClient;
use App\Services\Telegram\TelegramManager;
use App\Services\Telegram\TelegramMode;
use getID3;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Server-side disk cache of track audio fetched from Telegram. */
class TrackCache
{
    public function __construct(
        private TelegramManager $telegram,
        private BotClient $bot,
        private CoverService $covers,
    ) {}

    public function relativePath(Track $track): string
    {
        $ext = strtolower(pathinfo((string) $track->file_name, PATHINFO_EXTENSION)) ?: match ($track->mime_type) {
            'audio/mp4', 'audio/x-m4a', 'audio/m4a' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/wav', 'audio/x-wav' => 'wav',
            default => 'mp3',
        };

        return config('music.cache_dir').'/'.$track->channel_id.'/'.$track->message_id.'.'.$ext;
    }

    /** Download the whole file from Telegram unless it is already on disk. Returns the absolute path. */
    public function ensureCached(Track $track): string
    {
        if ($track->isCached()) {
            return $track->cacheAbsolutePath();
        }

        return Cache::lock('track-cache-'.$track->id, 900)->block(900, function () use ($track) {
            $track->refresh();
            if ($track->isCached()) {
                return $track->cacheAbsolutePath();
            }

            $relative = $this->relativePath($track);
            $absolute = Storage::path($relative);
            $partial = $absolute.'.part';
            File::ensureDirectoryExists(dirname($absolute));
            File::delete($partial);

            if (self::viaBot($track)) {
                $this->bot->download($track->file_id, $partial);
            } else {
                $message = $this->telegram->getMessage($track->channel->inputPeer(), (int) $track->message_id);
                if (! $message) {
                    throw new RuntimeException('The Telegram message for this track no longer exists.');
                }
                $this->telegram->downloadToFile($message, $partial);
            }
            rename($partial, $absolute);

            // Cache bookkeeping is server-side only: leave updated_at alone, since clients version
            // cover URLs and the catalog sync by it.
            Track::withoutTimestamps(fn () => $track->forceFill([
                'cache_path' => $relative,
                'cached_at' => now(),
                'cache_used_at' => now(),
            ])->save());

            $this->enrichFromFile($track, $absolute);

            return $absolute;
        });
    }

    /** Whether this track's audio is fetched through the bot rather than the user account. */
    public static function viaBot(Track $track): bool
    {
        return TelegramMode::isBot() && $track->file_id !== null;
    }

    public function touch(Track $track): void
    {
        if (! $track->cache_used_at || $track->cache_used_at->lt(now()->subHour())) {
            Track::withoutTimestamps(fn () => $track->forceFill(['cache_used_at' => now()])->saveQuietly());
        }
    }

    /** Fill blanks from the file's ID3/Vorbis tags and pull embedded artwork. */
    public function enrichFromFile(Track $track, string $path): void
    {
        try {
            $info = (new getID3)->analyze($path);
            \getid3_lib::CopyTagsToComments($info);
            $tags = $info['comments'] ?? [];

            if (! $track->metadata_locked) {
                $first = fn (string $key) => isset($tags[$key][0]) ? trim((string) $tags[$key][0]) : null;
                $track->album ??= $first('album') ?: null;
                $track->genre ??= $first('genre') ?: null;
                $track->artist ??= $first('artist') ?: null;
                $year = (int) ($first('year') ?? $first('date') ?? 0);
                $track->year ??= ($year > 1800 && $year < 2200) ? $year : null;
                if (! $track->duration && isset($info['playtime_seconds'])) {
                    $track->duration = (int) round($info['playtime_seconds']);
                }
                $track->save();
            }

            $picture = $tags['picture'][0]['data'] ?? null;
            if ($picture) {
                $this->covers->store($track, $picture);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not read tags', ['track' => $track->id, 'error' => $e->getMessage()]);
        }
    }

    /** Embedded lyrics from the cached file, if any. */
    public function embeddedLyrics(Track $track): ?string
    {
        if (! $track->isCached()) {
            return null;
        }
        try {
            $info = (new getID3)->analyze($track->cacheAbsolutePath());
            \getid3_lib::CopyTagsToComments($info);
            $lyrics = $info['comments']['unsynchronised_lyric'][0] ?? $info['comments']['lyrics'][0] ?? null;

            return $lyrics ? trim($lyrics) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Delete least recently used files until the cache fits the configured size. Returns freed bytes. */
    public function prune(?int $maxBytes = null): int
    {
        $maxBytes ??= config('music.cache_max_mb') * 1024 * 1024;
        if ($maxBytes <= 0) {
            return 0; // Unlimited.
        }
        $tracks = Track::withTrashed()->whereNotNull('cache_path')->orderBy('cache_used_at')->get();

        $total = $tracks->sum(fn (Track $t) => $t->isCached() ? filesize($t->cacheAbsolutePath()) : 0);
        $freed = 0;

        foreach ($tracks as $track) {
            if ($total <= $maxBytes) {
                break;
            }
            $path = $track->cacheAbsolutePath();
            $size = is_file($path) ? filesize($path) : 0;
            File::delete($path);
            Track::withoutTimestamps(fn () => $track->forceFill(['cache_path' => null, 'cached_at' => null])->saveQuietly());
            $total -= $size;
            $freed += $size;
        }

        return $freed;
    }

    public static function hasFreeSpace(): bool
    {
        $free = @disk_free_space(Storage::path(''));

        return $free === false || $free > config('music.prefetch.min_free_mb') * 1024 * 1024;
    }

    public function usage(): array
    {
        $dir = Storage::path(config('music.cache_dir'));
        $bytes = 0;
        $files = 0;
        if (is_dir($dir)) {
            foreach (File::allFiles($dir) as $file) {
                $bytes += $file->getSize();
                $files++;
            }
        }

        return ['bytes' => $bytes, 'files' => $files, 'limit_bytes' => config('music.cache_max_mb') * 1024 * 1024];
    }
}
