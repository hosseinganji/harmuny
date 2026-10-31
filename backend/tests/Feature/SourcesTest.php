<?php

namespace Tests\Feature;

use App\Jobs\NotifyNewTracksJob;
use App\Models\Channel;
use App\Models\Play;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\User;
use App\Services\Telegram\TrackUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SourcesTest extends TestCase
{
    use RefreshDatabase;

    private function channelPost(int $id, string $artist, string $title, int $duration = 200): Track
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@music'], ['telegram_id' => -1001, 'title' => 'Music', 'username' => 'music']);

        return TrackUpserter::upsert($channel, [
            'message_id' => $id, 'title' => $title, 'artist' => $artist, 'duration' => $duration, 'file_size' => 5_000_000,
            'mime_type' => 'audio/mpeg', 'has_cover' => false, 'posted_at' => now(),
        ]);
    }

    /** A few MPEG audio frames: enough for the upload to be recognised as an MP3. */
    private function mp3(string $name): UploadedFile
    {
        $frame = "\xFF\xFB\x90\x64".str_repeat("\0", 413);

        return UploadedFile::fake()->createWithContent($name, str_repeat($frame, 40));
    }

    public function test_the_same_song_is_never_added_twice(): void
    {
        $first = $this->channelPost(1, 'Shayea', 'Kerm');
        $this->assertSame('shayea|kerm', $first->match_key);
        $this->assertSame('https://t.me/music/1', $first->source_url);

        // Posted again with decorations, under the artist's name, and a second longer: still the same song.
        $again = $this->channelPost(2, 'Shayea', 'Shayea - Kerm (Official Audio) [320]', 201);
        $this->assertTrue($again->is($first));
        // A different version (much longer) is its own song.
        $this->assertFalse($this->channelPost(3, 'Shayea', 'Kerm', 320)->is($first));
        $this->assertSame(2, Track::query()->count());
    }

    public function test_only_admins_upload_songs_and_duplicates_are_turned_away(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs(User::factory()->create())->postJson('/api/admin/uploads', ['file' => $this->mp3('a.mp3')])->assertForbidden();

        $res = $this->actingAs($admin)->postJson('/api/admin/uploads', ['file' => $this->mp3('Hiphopologist - Ghabil.mp3')])->assertCreated();
        $this->assertFalse($res->json('duplicate'));
        $track = Track::query()->findOrFail($res->json('data.id'));
        $this->assertSame(['upload', 'Hiphopologist', 'Ghabil', $admin->id], [$track->source, $track->artist, $track->title, $track->added_by]);
        $this->assertTrue($track->isCached());
        $this->assertTrue($track->isPinned());

        $again = $this->actingAs($admin)->postJson('/api/admin/uploads', ['file' => $this->mp3('x.mp3'), 'artist' => 'Hiphopologist', 'title' => 'Ghabil'])->assertOk();
        $this->assertTrue($again->json('duplicate'));
        $this->assertSame($track->id, $again->json('data.id'));
        $this->assertSame(1, Track::query()->count());

        // The details say where it came from and who brought it.
        $details = $this->actingAs(User::factory()->create())->getJson("/api/tracks/{$track->id}/details")->assertOk();
        $this->assertSame('upload', $details->json('source'));
        $this->assertSame($admin->name, $details->json('added_by.name'));
        $this->assertTrue($details->json('on_server'));
    }

    public function test_details_of_a_channel_song_link_to_its_post(): void
    {
        $track = $this->channelPost(7, 'Shayea', 'Kerm');
        $details = $this->actingAs(User::factory()->create())->getJson("/api/tracks/{$track->id}/details")->assertOk();
        $this->assertSame(['telegram', 'https://t.me/music/7', 'Music'], [$details->json('source'), $details->json('source_url'), $details->json('channel.title')]);
        $this->assertSame(200, $details->json('bitrate'));
    }

    public function test_followers_get_their_artists_by_name_and_a_release_radar(): void
    {
        $fan = User::factory()->create(['preferences' => ['language' => 'fa']]);
        $this->actingAs($fan)->postJson('/api/follows', ['artist' => 'Shayea'])->assertOk()->assertJsonPath('data', ['Shayea']);
        $track = $this->channelPost(1, 'Shayea', 'Kerm');
        $other = $this->channelPost(2, 'Googoosh', 'Pol');
        $this->assertTrue($this->actingAs($fan)->getJson('/api/artist?name=Shayea')->json('following'));

        $payload = NotifyNewTracksJob::followedPayload([$track], 'fa');
        $this->assertSame('🎤 آهنگ جدید از Shayea', $payload['title']);

        $radar = $this->actingAs($fan)->getJson('/api/mixes/release-radar')->assertOk();
        $this->assertSame(1, $radar->json('personal'));
        $this->assertSame([$track->id, $other->id], array_column($radar->json('data'), 'id'));

        $this->actingAs($fan)->deleteJson('/api/follows', ['artist' => 'Shayea'])->assertOk()->assertJsonPath('data', []);
    }

    public function test_requests_are_answered_when_the_song_arrives(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $id = $this->actingAs($member)->postJson('/api/requests', ['title' => 'Kerm', 'artist' => 'Shayea'])->assertCreated()->json('data.id');
        $this->actingAs($member)->postJson('/api/requests', ['title' => 'Something else'])->assertCreated();
        $this->assertCount(2, $this->actingAs($admin)->getJson('/api/requests')->json('data'));
        $this->assertCount(2, $this->actingAs($member)->getJson('/api/requests')->json('data'));
        $this->assertCount(0, $this->actingAs(User::factory()->create())->getJson('/api/requests')->json('data'));

        $track = $this->channelPost(1, 'Shayea', 'Kerm (Official Audio)');
        $request = SongRequest::query()->findOrFail($id);
        $this->assertSame([SongRequest::FULFILLED, $track->id], [$request->status, $request->track_id]);

        // Admins can answer by hand or turn one down; members can't.
        $other = SongRequest::query()->where('title', 'Something else')->firstOrFail();
        $this->actingAs($member)->patchJson("/api/requests/{$other->id}", ['status' => 'rejected'])->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/requests/{$other->id}", ['status' => 'rejected'])->assertOk()->assertJsonPath('data.status', 'rejected');

        // Asking for a song the library already has answers at once.
        $this->actingAs($member)->postJson('/api/requests', ['title' => 'Kerm'])->assertCreated()->assertJsonPath('data.status', 'fulfilled');
    }

    public function test_duplicates_already_in_the_library_are_merged_keeping_plays_and_likes(): void
    {
        $user = User::factory()->create();
        $channel = Channel::query()->create(['peer' => '@music', 'telegram_id' => -1001, 'title' => 'Music']);
        // Two copies that got in before duplicates were caught.
        $a = Track::query()->create(['channel_id' => $channel->id, 'message_id' => 1, 'title' => 'Kerm', 'artist' => 'Shayea', 'duration' => 200]);
        $b = Track::query()->create(['channel_id' => $channel->id, 'message_id' => 2, 'title' => 'Kerm', 'artist' => 'Shayea', 'duration' => 200]);
        $b->forceFill(['play_count' => 3])->save();
        Play::query()->create(['user_id' => $user->id, 'track_id' => $a->id, 'seconds' => 100, 'played_at' => now()]);
        $this->actingAs($user)->postJson("/api/tracks/{$a->id}/like")->assertSuccessful();

        $this->artisan('music:dedupe')->assertSuccessful();

        $this->assertSoftDeleted($a);
        $this->assertSame(1, Play::query()->where('track_id', $b->id)->count());
        $this->assertTrue($this->actingAs($user)->getJson('/api/likes')->collect('data')->pluck('id')->contains($b->id));
        // The channel post coming round again doesn't bring the merged copy back.
        TrackUpserter::upsert($channel, ['message_id' => 1, 'title' => 'Kerm', 'artist' => 'Shayea', 'duration' => 200]);
        $this->assertSoftDeleted($a);
    }
}
