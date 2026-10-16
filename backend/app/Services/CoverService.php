<?php

namespace App\Services;

use App\Models\Track;
use App\Services\Telegram\BotClient;
use App\Services\Telegram\TelegramManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CoverService
{
    public function __construct(private TelegramManager $telegram, private BotClient $bot) {}

    public function relativePath(Track $track): string
    {
        return config('music.covers_dir').'/'.intdiv($track->id, 1000).'/'.$track->id.'.jpg';
    }

    public function store(Track $track, string $bytes): void
    {
        $replacing = Storage::exists($this->relativePath($track));
        Storage::put($this->relativePath($track), $bytes);
        if (! $track->has_cover) {
            $track->forceFill(['has_cover' => true])->saveQuietly();
        }
        // Clients version cover URLs by updated_at, so bump it when the image changes.
        if ($replacing) {
            $track->touch();
        }
    }

    /** Absolute path to the cover if it is already on disk. */
    public function cached(Track $track): ?string
    {
        $relative = $this->relativePath($track);

        return Storage::exists($relative) ? Storage::path($relative) : null;
    }

    /** Absolute path to the cover, fetching the Telegram thumbnail if needed (slow; used by FetchCoverJob). */
    public function resolve(Track $track): ?string
    {
        $relative = $this->relativePath($track);
        if (Storage::exists($relative)) {
            return Storage::path($relative);
        }
        if (! $track->has_cover || ! $track->channel) {
            return null;
        }

        return Cache::lock('cover-'.$track->id, 60)->block(60, function () use ($track, $relative) {
            if (Storage::exists($relative)) {
                return Storage::path($relative);
            }
            try {
                if (TrackCache::viaBot($track)) {
                    $bytes = $track->thumb_file_id ? $this->bot->fileBytes($track->thumb_file_id) : null;
                } else {
                    $message = $this->telegram->getMessage($track->channel->inputPeer(), (int) $track->message_id);
                    $bytes = $message ? $this->telegram->downloadThumb($message) : null;
                }
            } catch (\Throwable $e) {
                Log::warning('Cover download failed', ['track' => $track->id, 'error' => $e->getMessage()]);

                return null;
            }

            if (! $bytes) {
                $track->forceFill(['has_cover' => false])->saveQuietly();

                return null;
            }
            $this->store($track, $bytes);

            return Storage::path($relative);
        });
    }
}
