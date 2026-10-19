<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $title = 'Song'): Track
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@music'], ['telegram_id' => -1001, 'title' => 'Music']);
        static $msg = 1;

        return Track::query()->create([
            'channel_id' => $channel->id, 'message_id' => $msg++, 'title' => $title, 'duration' => 200,
            'file_size' => 1000, 'mime_type' => 'audio/mpeg', 'posted_at' => now(),
        ]);
    }

    public function test_online_list_shows_what_members_are_playing(): void
    {
        $sara = User::factory()->create(['name' => 'Sara']);
        $me = User::factory()->create();
        $song = $this->track('Kerm');

        $this->actingAs($sara)->postJson('/api/presence', ['track_id' => $song->id])->assertOk();
        $row = collect($this->actingAs($me)->getJson('/api/online')->json('data'))->firstWhere('name', 'Sara');
        $this->assertSame('Kerm', $row['now_playing']['title']);

        // Paused: nothing to show.
        $this->actingAs($sara)->postJson('/api/presence', ['track_id' => null])->assertOk();
        $row = collect($this->actingAs($me)->getJson('/api/online')->json('data'))->firstWhere('name', 'Sara');
        $this->assertNull($row['now_playing']);
    }

    public function test_reported_songs_go_stale(): void
    {
        $sara = User::factory()->create(['name' => 'Sara']);
        $this->actingAs($sara)->postJson('/api/presence', ['track_id' => $this->track()->id]);
        $sara->forceFill(['now_playing_at' => now()->subMinutes(10), 'last_seen_at' => now()])->save();

        $row = collect($this->actingAs(User::factory()->create())->getJson('/api/online')->json('data'))->firstWhere('name', 'Sara');
        $this->assertNull($row['now_playing']);
    }

    public function test_collaborators_can_add_and_remove_songs_but_not_rename(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create(['name' => 'Friend']);
        $stranger = User::factory()->create();
        $a = $this->track('A');
        $b = $this->track('B');

        $playlist = $this->actingAs($owner)->postJson('/api/playlists', ['name' => 'Road trip', 'track_ids' => [$a->id]])->json('data.id');

        // Not shared yet: the friend can neither see nor add.
        $this->actingAs($friend)->getJson("/api/playlists/$playlist")->assertNotFound();
        $this->actingAs($friend)->postJson("/api/playlists/$playlist/tracks", ['track_ids' => [$b->id]])->assertForbidden();

        $this->actingAs($owner)->putJson("/api/playlists/$playlist/collaborators", ['user_ids' => [$friend->id]])
            ->assertOk()->assertJsonPath('data.is_collaborative', true)->assertJsonPath('data.collaborators.0.name', 'Friend');

        $res = $this->actingAs($friend)->postJson("/api/playlists/$playlist/tracks", ['track_ids' => [$b->id]])->assertOk();
        $res->assertJsonPath('data.can_edit', true)->assertJsonPath('data.is_owner', false)->assertJsonPath('data.tracks.1.added_by.name', 'Friend');
        $this->actingAs($friend)->patchJson("/api/playlists/$playlist", ['name' => 'Mine now'])->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/playlists/$playlist/tracks", ['track_ids' => [$b->id]])->assertForbidden();

        // It shows up in the friend's library, and leaving removes it.
        $this->assertContains($playlist, array_column($this->actingAs($friend)->getJson('/api/playlists')->json('data'), 'id'));
        $this->actingAs($friend)->deleteJson("/api/playlists/$playlist/collaborators/me")->assertOk();
        $this->assertNotContains($playlist, array_column($this->actingAs($friend)->getJson('/api/playlists')->json('data'), 'id'));
    }
}
