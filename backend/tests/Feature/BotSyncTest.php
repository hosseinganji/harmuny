<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Track;
use App\Models\User;
use App\Services\Telegram\BotChannelSyncer;
use App\Services\Telegram\BotUpdates;
use App\Services\Telegram\TelegramMode;
use App\Support\MediaKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BotSyncTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL = -1001234567890;
    private const DUMP = 555;

    /** @var array<int, array> posts in the fake channel, by message id */
    private array $posts = [];
    private array $calls = [];
    private array $pendingUpdates = [];
    private int $rateLimitOnce = 0;
    private string $webhook = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['music.bot.scan_gap' => 5]);
        TelegramMode::set(TelegramMode::BOT);
        Setting::putSecret('telegram.bot_token', '123:'.str_repeat('a', 35));
        Setting::put('telegram.dump_chat_id', self::DUMP);
        $this->fakeTelegram();
    }

    private function audio(string $fileId, string $title, string $performer, int $size = 4_000_000): array
    {
        return ['audio' => [
            'file_id' => $fileId, 'file_unique_id' => 'u'.$fileId, 'duration' => 200, 'title' => $title,
            'performer' => $performer, 'file_name' => "$performer - $title.mp3", 'mime_type' => 'audio/mpeg', 'file_size' => $size,
            'thumbnail' => ['file_id' => 'thumb-'.$fileId, 'width' => 320, 'height' => 320],
        ]];
    }

    private function fakeTelegram(): void
    {
        $nextCopyId = 1000;
        Http::fake(function (Request $request) use (&$nextCopyId) {
            $url = $request->url();
            if (str_contains($url, '/file/bot')) {
                return Http::response(str_repeat('M', 300), 200);
            }
            $method = basename(parse_url($url, PHP_URL_PATH));
            $data = $request->data();
            $this->calls[] = [$method, $data];
            $ok = fn ($result) => Http::response(['ok' => true, 'result' => $result]);
            $fail = fn (int $code, string $text, array $extra = []) => Http::response(['ok' => false, 'error_code' => $code, 'description' => $text, ...$extra], $code);

            return match ($method) {
                'getMe' => $ok(['id' => 42, 'is_bot' => true, 'first_name' => 'Solix', 'username' => 'solix_bot']),
                'getChat' => $ok(['id' => self::CHANNEL, 'type' => 'channel', 'title' => 'Solix Music', 'username' => 'solix_music']),
                'getChatMember' => $ok(['status' => 'administrator', 'user' => ['id' => 42]]),
                'getUpdates' => $ok(array_splice($this->pendingUpdates, 0)),
                'deleteMessage', 'sendMessage', 'deleteWebhook' => $ok(true),
                'getWebhookInfo' => $ok(['url' => $this->webhook, 'pending_update_count' => 0]),
                'getFile' => $ok(['file_id' => $data['file_id'], 'file_path' => 'music/'.$data['file_id'].'.mp3']),
                'forwardMessage' => (function () use ($data, $fail, $ok, &$nextCopyId) {
                    if ($this->rateLimitOnce > 0) {
                        $this->rateLimitOnce--;

                        return $fail(429, 'Too Many Requests: retry after 0', ['parameters' => ['retry_after' => 0]]);
                    }
                    $post = $this->posts[$data['message_id']] ?? null;
                    if ($post === null) {
                        return $fail(400, 'Bad Request: message to forward not found');
                    }
                    if ($post === 'service') {
                        return $fail(400, "Bad Request: message can't be forwarded");
                    }

                    return $ok(['message_id' => $nextCopyId++, 'chat' => ['id' => self::DUMP, 'type' => 'private'],
                        'date' => time(), 'forward_origin' => ['type' => 'channel', 'date' => 1700000000 + $data['message_id']], ...$post]);
                })(),
                default => $fail(400, "unexpected $method"),
            };
        });
    }

    private function channel(): Channel
    {
        return Channel::query()->create(['peer' => '@solix_music']);
    }

    private function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }

    public function test_history_scan_imports_audio_and_skips_the_rest(): void
    {
        $this->posts = [
            1 => 'service',
            2 => $this->audio('f2', 'Gole Yakh', 'Kourosh Yaghmaei'),
            3 => ['text' => 'hello'],
            // 4 was deleted
            5 => $this->audio('f5', 'Huge', 'FLAC Guy', 40_000_000),
            6 => ['document' => ['file_id' => 'f6', 'file_name' => 'Googoosh - Pol.mp3', 'mime_type' => 'audio/mpeg', 'file_size' => 5_000_000]],
        ];
        $channel = $this->channel();

        $found = app(BotChannelSyncer::class)->sync($channel);

        $this->assertSame(2, $found);
        $channel->refresh();
        $this->assertSame(self::CHANNEL, (int) $channel->telegram_id);
        $this->assertSame('Solix Music', $channel->title);
        $this->assertSame(6, (int) $channel->last_message_id);
        $this->assertSame(1, $channel->skipped_count);
        $this->assertSame('idle', $channel->sync_status);

        $this->assertEqualsCanonicalizing([2, 6], Track::query()->pluck('message_id')->all());
        $pol = Track::query()->where('message_id', 6)->first();
        $this->assertSame('Pol', $pol->title);
        $this->assertSame('Googoosh', $pol->artist);
        $this->assertSame('f6', $pol->file_id);
        $gole = Track::query()->where('message_id', 2)->first();
        $this->assertSame('thumb-f2', $gole->thumb_file_id);
        $this->assertSame(1700000002, $gole->posted_at->timestamp);

        // Every forwarded copy (2, 3, 5, 6) is removed from the scratch chat.
        $this->assertSame(4, $this->callCount('deleteMessage'));
        // Ids 7..11 are the miss run that ends the scan.
        $this->assertSame(11, $this->callCount('forwardMessage'));
    }

    public function test_incremental_sync_continues_after_the_last_post(): void
    {
        $this->posts = [1 => $this->audio('a', 'One', 'X')];
        $channel = $this->channel();
        app(BotChannelSyncer::class)->sync($channel);

        $this->posts[2] = $this->audio('b', 'Two', 'X');
        $this->calls = [];
        app(BotChannelSyncer::class)->sync($channel->fresh());

        $this->assertSame(2, Track::query()->count());
        $first = collect($this->calls)->firstWhere(0, 'forwardMessage');
        $this->assertSame(2, $first[1]['message_id']);
    }

    public function test_updates_add_new_posts_and_register_the_scratch_chat(): void
    {
        Setting::put('telegram.dump_chat_id', null);
        $channel = $this->channel();
        $channel->update(['telegram_id' => self::CHANNEL]);

        $this->pendingUpdates = [
            ['update_id' => 10, 'message' => ['message_id' => 1, 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['first_name' => 'Hossein'], 'text' => '/start']],
            ['update_id' => 11, 'channel_post' => ['message_id' => 90, 'chat' => ['id' => self::CHANNEL, 'type' => 'channel'], 'date' => 1700000000, ...$this->audio('n', 'New Song', 'Ebi')]],
        ];

        $highest = app(BotUpdates::class)->poll();

        $this->assertSame(777, Setting::get('telegram.dump_chat_id'));
        $this->assertSame(12, Setting::get('telegram.update_offset'));
        $this->assertSame([self::CHANNEL => 90], $highest);
        $this->assertSame('New Song', Track::query()->where('message_id', 90)->value('title'));
    }

    public function test_rate_limits_are_retried(): void
    {
        $this->posts = [1 => $this->audio('a', 'One', 'X')];
        $this->rateLimitOnce = 1;

        app(BotChannelSyncer::class)->sync($this->channel());

        $this->assertSame(1, Track::query()->count());
    }

    public function test_full_sync_removes_deleted_posts(): void
    {
        $this->posts = [1 => $this->audio('a', 'One', 'X'), 2 => $this->audio('b', 'Two', 'X')];
        $channel = $this->channel();
        app(BotChannelSyncer::class)->sync($channel);

        unset($this->posts[1]);
        app(BotChannelSyncer::class)->sync($channel->fresh(), full: true);

        $this->assertSame([2], Track::query()->pluck('message_id')->all());
        $this->assertSame(1, Track::onlyTrashed()->count());
    }

    public function test_streaming_downloads_through_the_bot(): void
    {
        $this->posts = [1 => $this->audio('a', 'One', 'X')];
        app(BotChannelSyncer::class)->sync($this->channel());
        $track = Track::query()->first();
        $user = User::factory()->create();

        $response = $this->get("/api/tracks/{$track->id}/stream?k=".MediaKey::for($user), ['Range' => 'bytes=0-9']);

        $response->assertStatus(206);
        $this->assertSame(str_repeat('M', 10), $response->streamedContent());
        $this->assertTrue($track->fresh()->isCached());
        Storage::delete($track->fresh()->cache_path);
    }

    public function test_a_leftover_webhook_is_removed(): void
    {
        $this->webhook = 'https://old.example.ngrok-free.dev/hook';
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/admin/telegram/bot/check')->assertOk();

        $this->assertSame(1, $this->callCount('deleteWebhook'));
        $delete = collect($this->calls)->firstWhere(0, 'deleteWebhook');
        $this->assertFalse($delete[1]['drop_pending_updates']);
    }

    public function test_admin_sets_up_the_bot(): void
    {
        Setting::putSecret('telegram.bot_token', null);
        Setting::put('telegram.dump_chat_id', null);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/admin/telegram/bot', ['token' => '999:'.str_repeat('b', 35)])
            ->assertOk()
            ->assertJsonPath('mode', 'bot')
            ->assertJsonPath('status', 'bot_start')
            ->assertJsonPath('bot.username', 'solix_bot');

        $this->pendingUpdates = [['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 321, 'type' => 'private'], 'from' => ['first_name' => 'H'], 'text' => '/start']]];
        $this->actingAs($admin)->postJson('/api/admin/telegram/bot/check')->assertJsonPath('status', 'logged_in');

        $this->actingAs($admin)->postJson('/api/admin/channels', ['peer' => 'https://t.me/solix_music'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Solix Music');
    }
}
