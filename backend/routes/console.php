<?php

use App\Services\JamService;
use App\Services\Telegram\BotUpdates;
use App\Services\Telegram\TelegramMode;
use Illuminate\Support\Facades\Schedule;

Schedule::command('telegram:sync --queue')
    ->cron('*/'.max(1, config('music.sync_interval')).' * * * *')
    ->withoutOverlapping();

// A nightly full pass picks up edited captions and messages deleted from the channel.
// A bot has to forward every post to read it, so bot mode only does full passes on demand.
Schedule::command('telegram:sync --queue --full')->dailyAt('04:00')->when(fn () => ! TelegramMode::isBot());

// Bot mode: pick up new channel posts (and a /start to the bot) every minute.
Schedule::call(fn () => app(BotUpdates::class)->poll())
    ->name('telegram-bot-updates')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => TelegramMode::isBot() && app(\App\Services\Telegram\BotClient::class)->hasToken());

Schedule::command('music:prune-cache')->hourly();

// Keep a full copy of the library on this server: every 10 minutes, if Telegram is reachable,
// queue downloads for songs that are not stored yet.
Schedule::command('music:prefetch')->everyTenMinutes()->withoutOverlapping();

// Jams whose members all closed the app: drop members away for long, and end Jams nobody is left in.
Schedule::call(fn () => app(JamService::class)->tidyAll())->name('jams-tidy')->everyFiveMinutes()->withoutOverlapping();
