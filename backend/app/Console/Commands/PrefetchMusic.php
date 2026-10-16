<?php

namespace App\Console\Commands;

use App\Jobs\CacheTrackJob;
use App\Jobs\FetchCoverJob;
use App\Models\Track;
use App\Services\CoverService;
use App\Services\Telegram\BotClient;
use App\Services\TrackCache;
use App\Services\Telegram\TelegramMode;
use Illuminate\Console\Command;

class PrefetchMusic extends Command
{
    protected $signature = 'music:prefetch {--limit= : Queue at most this many songs}';

    protected $description = 'Queue a download to this server for every song and cover not stored yet, when Telegram is reachable';

    public function handle(BotClient $bot, CoverService $covers): int
    {
        if (! config('music.prefetch.enabled')) {
            return self::SUCCESS;
        }
        if (! TelegramMode::ready()) {
            $this->warn('Telegram is not connected; skipping.');

            return self::SUCCESS;
        }
        if (TelegramMode::isBot()) {
            try {
                $bot->call('getMe', [], null, 10);
            } catch (\Throwable $e) {
                $this->warn('Telegram is unreachable (VPN off?); skipping. '.$e->getMessage());

                return self::SUCCESS;
            }
        }
        if (! TrackCache::hasFreeSpace()) {
            $this->warn('Not enough free disk space; skipping.');

            return self::SUCCESS;
        }

        // Newest first, so fresh posts are ready soonest. Bots cannot download files over 20 MB.
        $tracks = Track::query()
            ->when(TelegramMode::isBot(), fn ($q) => $q->whereNotNull('file_id')->where('file_size', '<=', config('music.bot.max_file_bytes')))
            ->orderByDesc('posted_at')
            ->get()
            ->reject->isCached()
            ->when($this->option('limit'), fn ($c, $limit) => $c->take((int) $limit));

        foreach ($tracks as $track) {
            CacheTrackJob::dispatch($track);
        }

        $missingCovers = Track::query()->where('has_cover', true)->get()->reject(fn (Track $t) => $covers->cached($t));
        foreach ($missingCovers as $track) {
            FetchCoverJob::dispatch($track);
        }

        $this->info("Queued {$tracks->count()} songs and {$missingCovers->count()} covers.");

        return self::SUCCESS;
    }
}
