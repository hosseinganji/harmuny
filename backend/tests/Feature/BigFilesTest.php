<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Telegram\BotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BigFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_local_bot_api_server_hands_over_files_from_its_disk(): void
    {
        config(['music.bot.local_server' => true, 'music.bot.api_url' => 'http://telegram-bot-api:8081', 'music.bot.max_file_bytes' => 2000 * 1048576]);
        Setting::putSecret('telegram.bot_token', '123:'.str_repeat('a', 35));
        $served = storage_path('framework/testing/bot-api/song.mp3');
        File::ensureDirectoryExists(dirname($served));
        File::put($served, str_repeat('B', 1000));
        Http::fake(['*/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'big', 'file_path' => $served]])]);

        $target = storage_path('framework/testing/library/song.mp3');
        app(BotClient::class)->download('big', $target);

        $this->assertSame(1000, filesize($target));
        $this->assertFileDoesNotExist($served); // not kept twice
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/file/bot'));
        File::deleteDirectory(storage_path('framework/testing'));
    }

    public function test_the_size_limit_comes_from_the_environment(): void
    {
        $this->assertSame(20 * 1048576, config('music.bot.max_file_bytes'));
    }

    public function test_a_dropped_connection_to_telegram_is_retried(): void
    {
        Setting::putSecret('telegram.bot_token', '123:'.str_repeat('a', 35));
        $tries = 0;
        Http::fake(function () use (&$tries) {
            if (++$tries < 3) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 35: Recv failure: Connection reset by peer');
            }

            return Http::response(['ok' => true, 'result' => ['id' => 42, 'is_bot' => true, 'username' => 'harmony_bot']]);
        });

        $this->assertSame('harmony_bot', app(BotClient::class)->getMe()['username']);
        $this->assertSame(3, $tries);
    }
}
