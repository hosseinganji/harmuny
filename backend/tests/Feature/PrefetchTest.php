<?php

namespace Tests\Feature;

use App\Jobs\CacheTrackJob;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\Track;
use App\Services\Telegram\TelegramMode;
use App\Services\TrackCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrefetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        TelegramMode::set(TelegramMode::BOT);
        Setting::putSecret('telegram.bot_token', '123:'.str_repeat('a', 35));
        Setting::put('telegram.dump_chat_id', 5);
    }

    private function track(int $id, array $attrs = []): Track
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@c'], ['telegram_id' => -100]);

        return Track::query()->create([
            'channel_id' => $channel->id, 'message_id' => $id, 'file_id' => "f$id", 'title' => "Song $id",
            'file_size' => 5_000_000, 'mime_type' => 'audio/mpeg', 'posted_at' => now()->subMinutes($id), ...$attrs,
        ]);
    }

    public function test_queues_songs_not_stored_yet(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['id' => 1]])]);
        $missing = $this->track(1);
        $this->track(2, ['file_size' => 30_000_000]); // over the bot's 20 MB limit
        $stored = $this->track(3);
        Storage::put('music-cache/x.mp3', 'audio');
        $stored->forceFill(['cache_path' => 'music-cache/x.mp3'])->save();

        $this->artisan('music:prefetch')->assertSuccessful();

        Queue::assertPushedOn('downloads', CacheTrackJob::class, fn ($job) => $job->track->is($missing));
        Queue::assertPushed(CacheTrackJob::class, 1);
    }

    public function test_does_nothing_while_telegram_is_unreachable(): void
    {
        Queue::fake();
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->track(1);

        $this->artisan('music:prefetch')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_failed_download_is_retried_later_instead_of_failing(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $track = $this->track(1);

        (new CacheTrackJob($track))->handle(app(TrackCache::class));

        $this->assertFalse($track->fresh()->isCached());
    }
}
