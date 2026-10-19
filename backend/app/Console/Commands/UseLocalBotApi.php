<?php

namespace App\Console\Commands;

use App\Services\Telegram\BotClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Moves the bot from Telegram's public Bot API to our own local server (2 GB files instead of 20 MB).
 * Telegram requires the bot to log out of the public server first; it can't use it again for 10 minutes.
 */
class UseLocalBotApi extends Command
{
    protected $signature = 'telegram:use-local-bot-api';

    protected $description = 'Log the bot out of the public Bot API so the local Bot API server can take over';

    public function handle(BotClient $bot): int
    {
        if (! $bot->hasToken()) {
            $this->error('No bot is connected.');

            return self::FAILURE;
        }
        $response = Http::timeout(30)->post('https://api.telegram.org/bot'.$bot->token().'/logOut')->json();
        if (! ($response['ok'] ?? false) && ! str_contains((string) ($response['description'] ?? ''), 'Logged out')) {
            $this->error('Telegram refused: '.($response['description'] ?? 'unknown error'));

            return self::FAILURE;
        }
        $this->info('The bot is logged out of the public Bot API. Start the local server and run a full sync to pick up big files.');

        return self::SUCCESS;
    }
}
