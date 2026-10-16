<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\TrackCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Download a track's audio from Telegram into the server's storage. */
class CacheTrackJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Track $track)
    {
        $this->onQueue('downloads');
    }

    public function uniqueId(): string
    {
        return (string) $this->track->id;
    }

    public function handle(TrackCache $cache): void
    {
        if ($this->track->isCached() || ! TrackCache::hasFreeSpace()) {
            return;
        }

        try {
            $cache->ensureCached($this->track);
        } catch (\Throwable $e) {
            // Usually the VPN dropped. The next prefetch run queues the song again.
            Log::info('Track download postponed', ['track' => $this->track->id, 'error' => $e->getMessage()]);
        }
    }
}
