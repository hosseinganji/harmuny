<?php

return [
    // Where streamed tracks are cached on the server, relative to storage/app.
    'cache_dir' => env('MUSIC_CACHE_DIR', 'music-cache'),

    // Server-side audio cache limit in megabytes; 0 keeps every file (needed when prefetching the whole library).
    // Above the limit, least recently played files are pruned first.
    'cache_max_mb' => (int) env('MUSIC_CACHE_MAX_MB', 0),

    // Download every song to the server in the background, so plays never wait on Telegram.
    'prefetch' => [
        'enabled' => (bool) env('MUSIC_PREFETCH', true),
        // Stop downloading when the disk has less free space than this.
        'min_free_mb' => (int) env('MUSIC_MIN_FREE_MB', 2048),
    ],

    'covers_dir' => 'covers',
    'avatars_dir' => 'avatars',

    // MadelineProto session directory, relative to storage/app.
    'telegram_session' => env('TELEGRAM_SESSION', 'telegram/session.madeline'),

    // Bot API mode: sync through a bot that is an admin of the channel (no my.telegram.org keys needed).
    'bot' => [
        'api_url' => env('TELEGRAM_BOT_API_URL', 'https://api.telegram.org'),
        // The public Bot API refuses downloads above 20 MB.
        'max_file_bytes' => 20 * 1024 * 1024,
        // How many missing message ids in a row end a history scan.
        'scan_gap' => (int) env('TELEGRAM_SCAN_GAP', 60),
    ],

    // Optional proxy for reaching Telegram, e.g. socks5h://127.0.0.1:1080 or http://127.0.0.1:8080. Used by both modes.
    'telegram_proxy' => env('TELEGRAM_PROXY'),

    // Optional MTProxy for the user-account (MTProto) mode only, as a t.me/proxy?server=...&port=...&secret=... link.
    // The bot mode talks HTTPS to the Bot API, which an MTProxy cannot carry.
    'telegram_mtproxy' => env('TELEGRAM_MTPROXY'),

    // Minutes between automatic incremental channel syncs.
    'sync_interval' => (int) env('TELEGRAM_SYNC_INTERVAL', 10),

    'lyrics' => [
        'enabled' => (bool) env('LYRICS_ENABLED', true),
        'lrclib_url' => env('LRCLIB_URL', 'https://lrclib.net'),
    ],

    // Optional admin account created by `php artisan db:seed`.
    'admin' => [
        'username' => env('ADMIN_USERNAME'),
        'password' => env('ADMIN_PASSWORD'),
        'name' => env('ADMIN_NAME', 'Admin'),
    ],
];
