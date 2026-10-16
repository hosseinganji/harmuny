<?php

namespace App\Services\Telegram;

use App\Models\Setting;

/** Which way the server talks to Telegram: a bot (Bot API) or a user account (MTProto). */
class TelegramMode
{
    public const BOT = 'bot';
    public const USER = 'user';

    public static function current(): string
    {
        return Setting::get('telegram.mode', self::BOT) === self::USER ? self::USER : self::BOT;
    }

    public static function set(string $mode): void
    {
        Setting::put('telegram.mode', $mode === self::BOT ? self::BOT : self::USER);
    }

    public static function isBot(): bool
    {
        return self::current() === self::BOT;
    }

    /** Ready to sync: the bot is set up with a scratch chat, or the user account is logged in. */
    public static function ready(): bool
    {
        if (self::isBot()) {
            return app(BotClient::class)->hasToken() && Setting::get('telegram.dump_chat_id') !== null;
        }

        return app(TelegramManager::class)->isLoggedIn();
    }
}
