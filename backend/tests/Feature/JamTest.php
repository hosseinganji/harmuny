<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Jam;
use App\Models\PushSubscription;
use App\Models\Track;
use App\Models\User;
use App\Services\JamHub;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JamTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{topic: string, message: array}> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        $published = &$this->published;
        $this->app->instance(JamHub::class, new class($published) extends JamHub
        {
            public function __construct(private array &$published) {}

            public function enabled(): bool
            {
                return true;
            }

            public function publish(string $topic, array $message): void
            {
                $this->published[] = ['topic' => $topic, 'message' => $message];
            }
        });
        config(['services.mercure.subscriber_key' => str_repeat('k', 32)]);
    }

    private function track(string $artist = 'Shayea', int $duration = 200): Track
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@music'], ['telegram_id' => -1001, 'title' => 'Music']);
        static $msg = 1;

        return Track::query()->create([
            'channel_id' => $channel->id, 'message_id' => $msg++, 'title' => 'Song '.$msg, 'artist' => $artist,
            'duration' => $duration, 'file_size' => 1000, 'mime_type' => 'audio/mpeg', 'posted_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: array} code and snapshot */
    private function start(User $host, array $trackIds, array $extra = []): array
    {
        $res = $this->actingAs($host)->postJson('/api/jams', ['track_ids' => $trackIds, 'playing' => true] + $extra)->assertCreated();

        return [$res->json('data.code'), $res->json('data')];
    }

    private function lastEvent(): ?array
    {
        return end($this->published)['message']['event'] ?? null;
    }

    public function test_a_host_starts_a_jam_from_their_queue_and_friends_join_it(): void
    {
        [$host, $friend] = [User::factory()->create(), User::factory()->create()];
        $songs = [$this->track(), $this->track(), $this->track()];

        $res = $this->actingAs($host)->postJson('/api/jams', [
            'track_ids' => array_map(fn ($t) => $t->id, $songs), 'position_ms' => 42000, 'playing' => true,
        ])->assertCreated();
        $code = $res->json('data.code');
        $this->assertSame([$songs[0]->id, $songs[1]->id, $songs[2]->id], array_slice(array_column($res->json('data.queue'), 'track_id'), 0, 3));
        $this->assertSame($res->json('data.queue.0.uid'), $res->json('data.current_uid'));
        $this->assertTrue($res->json('data.playing'));
        $this->assertSame(42000, $res->json('data.position_ms'));
        // Members get a token for their Jam's live updates.
        $this->assertStringContainsString('/.well-known/mercure', $res->json('hub.url'));
        $this->assertSame('jam/'.Jam::query()->value('id'), $res->json('hub.topic'));

        // Before joining: the invite page sees the Jam, but no live updates.
        $this->actingAs($friend)->getJson("/api/jams/$code")->assertOk()->assertJsonPath('member', false)->assertJsonPath('hub', null);
        $this->actingAs($friend)->postJson("/api/jams/$code/playback", ['action' => 'pause'])->assertForbidden()->assertJsonPath('reason', 'not_member');

        $joined = $this->actingAs($friend)->postJson("/api/jams/$code/join", ['listening' => false])->assertOk();
        $this->assertTrue($joined->json('member'));
        $this->assertSame([$host->id, $friend->id], array_column($joined->json('data.members'), 'id'));
        $this->assertFalse($joined->json('data.members.1.listening'));
        $this->assertSame(['type' => 'join', 'user_id' => $friend->id], $this->lastEvent());

        // The online list shows who is in a Jam.
        $online = $this->actingAs($friend)->getJson('/api/online')->assertOk()->json('data');
        $this->assertSame($code, collect($online)->firstWhere('id', $host->id)['jam']['code']);
    }

    public function test_playback_is_anchored_to_the_server_clock_and_devices_finishing_a_song_move_it_on_once(): void
    {
        $host = User::factory()->create();
        $songs = [$this->track(), $this->track(), $this->track()];
        [$code, $jam] = $this->start($host, array_map(fn ($t) => $t->id, $songs), ['playing' => false]);

        $play = $this->actingAs($host)->postJson("/api/jams/$code/playback", ['action' => 'play', 'position_ms' => 10000])->json('data');
        $this->assertTrue($play['playing']);
        $this->assertSame(10000, $play['position_ms']);
        $this->assertEqualsWithDelta($play['server_now'], $play['anchor_ms'], 1000);

        // Two devices reach the end of the first song: only the first report moves the Jam on.
        $first = $jam['queue'][0]['uid'];
        $after = $this->actingAs($host)->postJson("/api/jams/$code/playback", ['action' => 'advance', 'from' => $first])->json('data');
        $this->assertSame($jam['queue'][1]['uid'], $after['current_uid']);
        $this->assertSame(0, $after['position_ms']);
        $again = $this->actingAs($host)->postJson("/api/jams/$code/playback", ['action' => 'advance', 'from' => $first])->json('data');
        $this->assertSame($jam['queue'][1]['uid'], $again['current_uid']);
        $this->assertSame($after['version'], $again['version']);

        // Nobody reports the end (every phone locked and offline): the server moves on by itself.
        $this->travel(200 + 10)->seconds();
        $show = $this->actingAs($host)->getJson("/api/jams/$code")->json('data');
        $this->assertSame($jam['queue'][2]['uid'], $show['current_uid']);
        $this->assertTrue($show['playing']);
    }

    public function test_guests_control_playback_only_when_the_host_allows_and_otherwise_vote_to_skip(): void
    {
        [$host, $a, $b] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $songs = [$this->track(), $this->track(), $this->track()];
        [$code, $jam] = $this->start($host, array_map(fn ($t) => $t->id, $songs));
        foreach ([$a, $b] as $u) {
            $this->actingAs($u)->postJson("/api/jams/$code/join")->assertOk();
        }

        $this->actingAs($a)->postJson("/api/jams/$code/playback", ['action' => 'pause'])->assertOk()->assertJsonPath('data.playing', false);
        $this->actingAs($b)->patchJson("/api/jams/$code", ['guests_control' => false])->assertForbidden()->assertJsonPath('reason', 'not_host');
        $this->actingAs($host)->patchJson("/api/jams/$code", ['guests_control' => false])->assertOk();
        $this->actingAs($a)->postJson("/api/jams/$code/playback", ['action' => 'play'])->assertForbidden()->assertJsonPath('reason', 'no_control');

        // Three here: two votes skip the song.
        $vote = $this->actingAs($a)->postJson("/api/jams/$code/playback", ['action' => 'vote'])->json('data');
        $this->assertSame($jam['queue'][0]['uid'], $vote['current_uid']);
        $this->assertSame(['users' => [$a->id], 'needed' => 2], $vote['skip_votes']);
        $this->assertSame(['type' => 'vote', 'user_id' => $a->id, 'votes' => 1, 'needed' => 2], $this->lastEvent());
        $skipped = $this->actingAs($b)->postJson("/api/jams/$code/playback", ['action' => 'vote'])->json('data');
        $this->assertSame($jam['queue'][1]['uid'], $skipped['current_uid']);
        $this->assertNull($skipped['skip_votes']);
        $this->assertSame(['type' => 'skip', 'user_id' => null, 'voted' => true], $this->lastEvent());
    }

    public function test_everyone_adds_songs_ahead_of_the_jam_mix_and_removes_only_their_own(): void
    {
        [$host, $guest] = [User::factory()->create(), User::factory()->create()];
        $songs = [$this->track(), $this->track(), $this->track()];
        $extra = [$this->track('Other'), $this->track('Other')];
        [$code] = $this->start($host, [$songs[0]->id, $songs[1]->id]);
        $this->actingAs($guest)->postJson("/api/jams/$code/join")->assertOk();
        $this->actingAs($host)->patchJson("/api/jams/$code", ['guests_control' => false])->assertOk();

        // The Jam mix filled the queue up behind the host's songs.
        $before = Jam::query()->first()->queue;
        $this->assertNull(end($before)['by']);

        $added = $this->actingAs($guest)->postJson("/api/jams/$code/queue", ['track_ids' => [$extra[0]->id]])->json('data.queue');
        $this->assertSame(['track_id' => $extra[0]->id, 'by' => $guest->id], array_intersect_key($added[2], ['track_id' => 0, 'by' => 0]));
        $this->assertSame('add', $this->lastEvent()['type']);

        $next = $this->actingAs($guest)->postJson("/api/jams/$code/queue", ['track_ids' => [$extra[1]->id], 'mode' => 'next'])->json('data.queue');
        $this->assertSame($extra[1]->id, $next[1]['track_id']);

        // A guest without control removes their own picks, not the host's.
        $this->actingAs($guest)->deleteJson("/api/jams/$code/queue/{$next[2]['uid']}")->assertForbidden();
        $this->actingAs($guest)->deleteJson("/api/jams/$code/queue/{$next[1]['uid']}")->assertOk();
        $this->actingAs($host)->deleteJson("/api/jams/$code/queue/{$next[0]['uid']}")->assertStatus(422); // playing now
        $this->actingAs($guest)->putJson("/api/jams/$code/queue", ['uids' => []])->assertForbidden();

        // Tapping a song: the host plays it now for everyone; a guest without control adds it.
        $played = $this->actingAs($host)->postJson("/api/jams/$code/queue", ['track_ids' => [$songs[2]->id], 'mode' => 'play'])->json('data');
        $this->assertSame($played['queue'][1]['uid'], $played['current_uid']);
        $this->assertSame($songs[2]->id, $played['queue'][1]['track_id']);
        $guestPick = $this->actingAs($guest)->postJson("/api/jams/$code/queue", ['track_ids' => [$songs[0]->id], 'mode' => 'play'])->json('data');
        $this->assertSame($played['current_uid'], $guestPick['current_uid']);
    }

    public function test_a_host_who_leaves_hands_the_jam_on_and_the_last_one_out_ends_it(): void
    {
        [$host, $guest] = [User::factory()->create(), User::factory()->create()];
        [$code] = $this->start($host, [$this->track()->id]);
        $this->actingAs($guest)->postJson("/api/jams/$code/join")->assertOk();

        $this->actingAs($host)->postJson("/api/jams/$code/leave")->assertOk();
        $this->assertSame($guest->id, Jam::query()->first()->host_id);
        $this->assertSame(['type' => 'leave', 'user_id' => $host->id, 'host_id' => $guest->id], $this->lastEvent());

        $this->actingAs($guest)->postJson("/api/jams/$code/leave")->assertOk();
        $this->assertNotNull(Jam::query()->first()->ended_at);
        $this->actingAs($guest)->postJson("/api/jams/$code/join")->assertStatus(410)->assertJsonPath('reason', 'ended');
    }

    public function test_joining_a_jam_leaves_the_previous_one_and_removed_members_cannot_come_back(): void
    {
        [$a, $b, $c] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        [$first] = $this->start($a, [$this->track()->id]);
        [$second] = $this->start($b, [$this->track()->id]);
        $this->actingAs($c)->postJson("/api/jams/$first/join")->assertOk();
        $this->actingAs($c)->postJson("/api/jams/$second/join")->assertOk();
        $this->assertSame($second, $this->actingAs($c)->getJson('/api/jams/current')->json('data.code'));
        $this->assertFalse(Jam::query()->where('code', $first)->first()->members()->whereKey($c->id)->exists());

        $this->actingAs($b)->deleteJson("/api/jams/$second/members/{$c->id}")->assertOk();
        $this->assertSame(['type' => 'kick', 'user_id' => $c->id, 'by' => $b->id], $this->lastEvent());
        $this->actingAs($c)->postJson("/api/jams/$second/ping")->assertForbidden()->assertJsonPath('reason', 'banned');
        $this->actingAs($c)->getJson('/api/jams/current')->assertOk()->assertJsonPath('data', null);
    }

    public function test_members_away_for_long_are_dropped_but_a_returning_phone_rejoins(): void
    {
        [$host, $guest] = [User::factory()->create(), User::factory()->create()];
        [$code] = $this->start($host, [$this->track(duration: 0)->id]);
        $this->actingAs($guest)->postJson("/api/jams/$code/join")->assertOk();

        $this->travel(20)->minutes();
        $this->actingAs($host)->postJson("/api/jams/$code/ping")->assertOk();
        $jam = Jam::query()->first();
        $this->assertSame([$host->id], $jam->members()->pluck('users.id')->all());

        // The guest's phone was locked all along; when it checks in again it is back in.
        $back = $this->actingAs($guest)->postJson("/api/jams/$code/ping")->assertOk();
        $this->assertTrue($back->json('member'));
        $this->assertCount(2, $back->json('data.members'));
    }

    public function test_reactions_go_to_everyone_and_invites_reach_the_invited_devices(): void
    {
        $sent = [];
        $this->app->instance(PushService::class, new class($sent) extends PushService
        {
            public function __construct(private array &$sent) {}

            public function send(iterable $subscriptions, array $payload): int
            {
                $this->sent[] = ['endpoints' => collect($subscriptions)->pluck('endpoint')->all(), 'payload' => $payload];

                return 1;
            }
        });
        [$host, $friend, $other] = [User::factory()->create(['name' => 'Soheil']), User::factory()->create(['preferences' => ['language' => 'fa']]), User::factory()->create()];
        foreach ([$friend, $other] as $u) {
            PushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', "e$u->id"), 'endpoint' => "https://push.example/$u->id", 'public_key' => 'k', 'auth_token' => 'a']);
        }
        [$code] = $this->start($host, [$this->track()->id]);

        $this->actingAs($host)->postJson("/api/jams/$code/react", ['emoji' => '🔥'])->assertOk();
        $this->assertSame(['type' => 'reaction', 'user_id' => $host->id, 'emoji' => '🔥'], array_intersect_key(end($this->published)['message'], ['type' => 0, 'user_id' => 0, 'emoji' => 0]));
        $this->actingAs($host)->postJson("/api/jams/$code/react", ['emoji' => 'nope'])->assertStatus(422);

        $this->actingAs($host)->postJson("/api/jams/$code/invite", ['user_ids' => [$friend->id]])->assertOk();
        $this->assertCount(1, $sent);
        $this->assertSame(["https://push.example/$friend->id"], $sent[0]['endpoints']);
        $this->assertSame('🎧 Soheil تو را به پخش گروهی دعوت کرد', $sent[0]['payload']['title']);
        $this->assertSame("/jam/$code", $sent[0]['payload']['url']);
    }
}
