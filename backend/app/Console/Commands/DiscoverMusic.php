<?php

namespace App\Console\Commands;

use App\Services\Discovery\TelegramDiscovery;
use Illuminate\Console\Command;

class DiscoverMusic extends Command
{
    protected $signature = 'music:discover';

    protected $description = 'Bring in songs posted this week by followed or popular artists in the discovery channels';

    public function handle(TelegramDiscovery $discovery): int
    {
        $result = $discovery->run(fn (string $line) => $this->line($line));

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
