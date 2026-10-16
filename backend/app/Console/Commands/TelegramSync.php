<?php

namespace App\Console\Commands;

use App\Jobs\SyncChannelJob;
use App\Models\Channel;
use App\Services\Telegram\BotChannelSyncer;
use App\Services\Telegram\ChannelSyncer;
use App\Services\Telegram\TelegramMode;
use Illuminate\Console\Command;

class TelegramSync extends Command
{
    protected $signature = 'telegram:sync
        {--channel= : Only this channel id}
        {--full : Walk the whole history and drop deleted messages}
        {--queue : Dispatch to the queue instead of running inline}';

    protected $description = 'Sync audio tracks from the configured Telegram channels';

    public function handle(): int
    {
        if (! TelegramMode::ready()) {
            $this->warn('Telegram is not connected; skipping sync.');

            return self::SUCCESS;
        }
        $syncer = TelegramMode::isBot() ? app(BotChannelSyncer::class) : app(ChannelSyncer::class);

        $channels = Channel::query()
            ->where('enabled', true)
            ->when($this->option('channel'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($channels as $channel) {
            if ($this->option('queue')) {
                SyncChannelJob::dispatch($channel, (bool) $this->option('full'));
                $this->info("Queued sync for {$channel->title}");

                continue;
            }
            try {
                $count = $syncer->sync($channel, (bool) $this->option('full'));
                $this->info("{$channel->title}: {$count} messages processed");
            } catch (\Throwable $e) {
                $this->error("{$channel->title}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
