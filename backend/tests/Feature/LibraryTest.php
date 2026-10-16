<?php

namespace Tests\Feature;

use App\Jobs\FetchCoverJob;
use App\Models\Channel;
use App\Models\Track;
use App\Models\User;
use App\Support\MediaKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private function track(array $attrs = []): Track
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@music'], ['telegram_id' => -1001, 'title' => 'Music']);
        static $msg = 1;

        return Track::query()->create([
            'channel_id' => $channel->id,
            'message_id' => $msg++,
            'title' => 'Song',
            'duration' => 200,
            'file_size' => 1000,
            'mime_type' => 'audio/mpeg',
            'posted_at' => now(),
            ...$attrs,
        ]);
    }

    public function test_search_normalizes_persian_letters(): void
    {
        $user = User::factory()->create();
        $this->track(['title' => 'دلتنگي', 'artist' => 'علي']);
        $this->track(['title' => 'Other']);

        $this->actingAs($user)->getJson('/api/search?q='.urlencode('دلتنگی علی'))
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('artists.0.artist', 'علي');
    }

    public function test_like_and_unlike(): void
    {
        $user = User::factory()->create();
        $track = $this->track();

        $this->actingAs($user)->postJson("/api/tracks/{$track->id}/like")->assertOk();
        $this->actingAs($user)->getJson('/api/likes')->assertJsonPath('data.0.liked', true);
        $this->actingAs($user)->deleteJson("/api/tracks/{$track->id}/like")->assertOk();
        $this->actingAs($user)->getJson('/api/likes')->assertJsonCount(0, 'data');
    }

    public function test_short_plays_are_not_counted(): void
    {
        $user = User::factory()->create();
        $track = $this->track();

        $this->actingAs($user)->postJson('/api/plays', ['plays' => [
            ['track_id' => $track->id, 'seconds' => 5],
            ['track_id' => $track->id, 'seconds' => 90],
        ]])->assertJsonPath('recorded', 1);

        $this->assertSame(1, $track->fresh()->play_count);
    }

    public function test_playlist_lifecycle(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$a, $b, $c] = [$this->track(), $this->track(), $this->track()];

        $id = $this->actingAs($owner)->postJson('/api/playlists', ['name' => 'Road', 'track_ids' => [$a->id, $b->id]])
            ->assertCreated()->json('data.id');

        // Duplicates are skipped by default.
        $entries = $this->actingAs($owner)->postJson("/api/playlists/{$id}/tracks", ['track_ids' => [$b->id, $c->id]])
            ->assertJsonCount(3, 'data.tracks')->json('data.tracks.*.entry_id');

        $this->actingAs($owner)->putJson("/api/playlists/{$id}/order", ['entry_ids' => array_reverse($entries)])
            ->assertJsonPath('data.tracks.0.id', $c->id);

        $this->actingAs($other)->getJson("/api/playlists/{$id}")->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/playlists/{$id}", ['is_public' => true])->assertOk();
        $this->actingAs($other)->getJson("/api/playlists/{$id}")->assertOk()->assertJsonPath('data.is_owner', false);
        $this->actingAs($other)->postJson("/api/playlists/{$id}/tracks", ['track_ids' => [$a->id]])->assertForbidden();

        $this->actingAs($owner)->deleteJson("/api/playlists/{$id}/tracks/{$entries[0]}")->assertJsonCount(2, 'data.tracks');
    }

    public function test_cached_track_streams_byte_ranges_with_media_key(): void
    {
        $user = User::factory()->create();
        $track = $this->track();
        Storage::put('music-cache/test.mp3', str_repeat('A', 100).str_repeat('B', 100));
        $track->forceFill(['cache_path' => 'music-cache/test.mp3'])->save();

        $this->get("/api/tracks/{$track->id}/stream")->assertUnauthorized();
        $this->get("/api/tracks/{$track->id}/stream?k=1.bogus")->assertUnauthorized();

        $response = $this->get("/api/tracks/{$track->id}/stream?k=".MediaKey::for($user), ['Range' => 'bytes=100-149']);
        $response->assertStatus(206);
        $this->assertSame(str_repeat('B', 50), $response->streamedContent());

        Storage::delete('music-cache/test.mp3');
    }

    public function test_playing_a_cached_track_keeps_its_version(): void
    {
        // Cover URLs and the catalog sync are versioned by updated_at; cache bookkeeping must not bump it.
        $user = User::factory()->create();
        $track = $this->track();
        Storage::put('music-cache/keep.mp3', 'audio');
        $track->forceFill(['cache_path' => 'music-cache/keep.mp3', 'cache_used_at' => now()->subDay()])->save();
        $before = $track->fresh()->updated_at;

        $this->travel(5)->minutes();
        $this->get("/api/tracks/{$track->id}/stream?k=".MediaKey::for($user))->assertOk();

        $this->assertTrue($track->fresh()->cache_used_at->isToday());
        $this->assertTrue($track->fresh()->updated_at->equalTo($before));
        Storage::delete('music-cache/keep.mp3');
    }

    public function test_online_lists_recently_seen_active_users(): void
    {
        $me = User::factory()->create();
        User::factory()->create(['name' => 'Sara', 'last_seen_at' => now()->subSeconds(30)]);
        User::factory()->create(['name' => 'Away', 'last_seen_at' => now()->subMinutes(10)]);
        User::factory()->pending()->create(['name' => 'Pending', 'last_seen_at' => now()]);

        $names = $this->actingAs($me)->getJson('/api/online')->assertOk()->json('data.*.name');

        $this->assertEqualsCanonicalizing([$me->name, 'Sara'], $names);
    }

    public function test_missing_cover_is_fetched_in_the_background(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $track = $this->track(['has_cover' => true]);

        $this->get("/api/tracks/{$track->id}/cover?k=".MediaKey::for($user))->assertNotFound();

        Queue::assertPushedOn('covers', FetchCoverJob::class);
    }

    public function test_media_key_is_revoked_by_password_change(): void
    {
        $user = User::factory()->create();
        $key = MediaKey::for($user);
        $this->assertTrue(MediaKey::resolve($key)->is($user));

        $user->update(['password' => 'another-password']);
        $this->assertNull(MediaKey::resolve($key));
    }

    public function test_sync_feed_reports_deletions(): void
    {
        $user = User::factory()->create();
        $keep = $this->track();
        $gone = $this->track();
        $since = now()->subMinute()->toIso8601String();
        $gone->delete();

        $this->actingAs($user)->getJson('/api/library/sync?since='.urlencode($since))
            ->assertOk()
            ->assertJsonPath('deleted', [$gone->id])
            ->assertJsonPath('tracks.0.id', $keep->id);

        $this->actingAs($user)->getJson('/api/library/sync')->assertJsonPath('full', true)->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.on_server', false);
    }
}
