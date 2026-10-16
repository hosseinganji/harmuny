<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Telegram\BotChannelSyncer;
use App\Services\Telegram\ChannelSyncer;
use App\Services\Telegram\TelegramMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncChannelJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public Channel $channel, public bool $full = false) {}

    public function uniqueId(): string
    {
        return (string) $this->channel->id;
    }

    public function handle(): void
    {
        $syncer = TelegramMode::isBot() ? app(BotChannelSyncer::class) : app(ChannelSyncer::class);
        $syncer->sync($this->channel->fresh(), $this->full);
    }
}
