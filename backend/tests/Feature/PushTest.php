<?php

namespace Tests\Feature;

use App\Jobs\NotifyNewTracksJob;
use App\Models\Channel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushService;
use App\Services\Telegram\TrackUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{endpoints: string[], payload: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $sent = &$this->sent;
        $this->app->instance(PushService::class, new class($sent) extends PushService
        {
            public function __construct(private array &$sent) {}

            public function send(iterable $subscriptions, array $payload): int
            {
                $endpoints = collect($subscriptions)->pluck('endpoint')->all();
                $this->sent[] = ['endpoints' => $endpoints, 'payload' => $payload];

                return count($endpoints);
            }
        });
    }

    private function postSong(int $id, string $title, \DateTimeInterface $postedAt): void
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@music'], ['telegram_id' => -1001, 'title' => 'Music']);
        TrackUpserter::upsert($channel, [
            'message_id' => $id, 'title' => $title, 'artist' => 'Shayea', 'duration' => 200, 'file_size' => 1000,
            'mime_type' => 'audio/mpeg', 'has_cover' => false, 'posted_at' => $postedAt,
        ]);
    }

    public function test_devices_can_subscribe_and_unsubscribe(): void
    {
        $user = User::factory()->create();
        $sub = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'BKey', 'auth' => 'AuthKey']];

        $this->actingAs($user)->getJson('/api/push/key')->assertOk()->assertJsonStructure(['public_key']);
        $this->actingAs($user)->postJson('/api/push/subscriptions', $sub)->assertCreated();
        $this->actingAs($user)->postJson('/api/push/subscriptions', $sub)->assertCreated(); // same device again
        $this->assertSame(1, PushSubscription::query()->count());

        $this->actingAs($user)->deleteJson('/api/push/subscriptions', ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_new_posts_are_batched_into_one_notification_in_each_members_language(): void
    {
        Queue::fake();
        $fa = User::factory()->create(['preferences' => ['language' => 'fa']]);
        $en = User::factory()->create();
        foreach ([$fa, $en] as $i => $u) {
            PushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', "e$i"), 'endpoint' => "https://push.example/$i", 'public_key' => 'k', 'auth_token' => 'a']);
        }

        $this->postSong(1, 'Old one', now()->subYear()); // found while reading history: no notification
        $this->postSong(2, 'Kerm', now());
        $this->postSong(3, 'Boht', now());

        Queue::assertPushed(NotifyNewTracksJob::class);
        $this->assertCount(2, Cache::get(NotifyNewTracksJob::PENDING_KEY));
        app()->call([new NotifyNewTracksJob, 'handle']);

        $this->assertCount(2, $this->sent);
        $byEndpoint = collect($this->sent)->keyBy(fn ($s) => $s['endpoints'][0]);
        $this->assertSame('🎵 ۲ آهنگ جدید', $byEndpoint['https://push.example/0']['payload']['title']);
        $this->assertSame('🎵 2 new songs', $byEndpoint['https://push.example/1']['payload']['title']);
        $this->assertStringContainsString('Shayea – Kerm', $byEndpoint['https://push.example/1']['payload']['body']);
        $this->assertStringNotContainsString('Old one', $byEndpoint['https://push.example/1']['payload']['body']);
        $this->assertNull(Cache::get(NotifyNewTracksJob::PENDING_KEY));
    }
}
