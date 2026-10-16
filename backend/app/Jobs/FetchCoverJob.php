<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\CoverService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Download a track's artwork from Telegram in the background, so web requests never wait on it. */
class FetchCoverJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(public Track $track)
    {
        $this->onQueue('covers');
    }

    public function uniqueId(): string
    {
        return (string) $this->track->id;
    }

    public function handle(CoverService $covers): void
    {
        $covers->resolve($this->track);
    }
}
