<?php

namespace App\Console\Commands;

use App\Services\TrackCache;
use Illuminate\Console\Command;

class PruneMusicCache extends Command
{
    protected $signature = 'music:prune-cache';

    protected $description = 'Delete least recently played cached audio files above the size limit';

    public function handle(TrackCache $cache): int
    {
        $freed = $cache->prune();
        $this->info('Freed '.round($freed / 1048576, 1).' MB');

        return self::SUCCESS;
    }
}
