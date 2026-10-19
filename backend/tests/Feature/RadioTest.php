<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use App\Services\RadioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RadioTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $artist, string $title, ?string $genre = null): Track
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@music'], ['telegram_id' => -1001, 'title' => 'Music']);
        static $msg = 1;

        return Track::query()->create([
            'channel_id' => $channel->id, 'message_id' => $msg++, 'title' => $title, 'artist' => $artist, 'genre' => $genre,
            'duration' => 200, 'file_size' => 1000, 'mime_type' => 'audio/mpeg', 'posted_at' => now(),
        ]);
    }

    private function play(User $user, Track $track, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            Play::query()->create(['user_id' => $user->id, 'track_id' => $track->id, 'seconds' => 120, 'played_at' => now()->subDays(1)]);
        }
    }

    public function test_artists_people_play_together_are_related(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $shayea = $this->track('Shayea', 'Kerm');
        $hiphopologist = $this->track('Hiphopologist', 'X');
        $this->track('Googoosh', 'Y');
        foreach ([$a, $b] as $u) {
            $this->play($u, $shayea);
            $this->play($u, $hiphopologist);
        }

        $related = app(RadioService::class)->relatedArtists('Shayea');

        $this->assertSame(['Hiphopologist'], $related->keys()->all());
    }

    public function test_radio_starts_with_the_seed_never_repeats_queued_songs_and_spreads_artists(): void
    {
        $seed = $this->track('Shayea', 'Kerm', 'Rap');
        $songs = collect(range(1, 8))->map(fn ($i) => $this->track('Shayea', "S$i", 'Rap'))
            ->merge(collect(range(1, 8))->map(fn ($i) => $this->track('Other', "O$i", 'Rap')));
        $queued = $songs->take(2)->pluck('id')->all();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/api/radio?'.http_build_query(['track_id' => $seed->id, 'exclude' => $queued]))
            ->assertOk();

        $titles = array_column($res->json('data'), 'title');
        $this->assertSame('Kerm', $titles[0]);
        $ids = array_column($res->json('data'), 'id');
        $this->assertEmpty(array_intersect($queued, $ids));
        $artists = array_column($res->json('data'), 'artist');
        // The seed's artist never plays three in a row while other artists are left to play.
        for ($i = 2; $i < count($artists); $i++) {
            $run = $artists[$i] === 'Shayea' && $artists[$i - 1] === 'Shayea' && $artists[$i - 2] === 'Shayea';
            $this->assertFalse($run && in_array('Other', array_slice($artists, $i), true), 'three in a row');
        }

        // Asking for more skips the seed.
        $more = $this->actingAs(User::factory()->create())->getJson('/api/radio?'.http_build_query(['track_id' => $seed->id, 'more' => 1, 'exclude' => $ids]))->json('data');
        $this->assertNotContains($seed->id, array_column($more, 'id'));
    }

    public function test_daily_mix_follows_taste_and_stays_the_same_all_day(): void
    {
        $me = User::factory()->create();
        $fav = collect(range(1, 6))->map(fn ($i) => $this->track('Shayea', "F$i"));
        collect(range(1, 20))->each(fn ($i) => $this->track("Artist$i", "R$i"));
        $this->play($me, $fav[0], 5);

        $first = array_column($this->actingAs($me)->getJson('/api/mixes/daily')->assertOk()->json('data'), 'id');
        $again = array_column($this->actingAs($me)->getJson('/api/mixes/daily')->json('data'), 'id');

        $this->assertSame($first, $again);
        $this->assertNotEmpty(array_intersect($fav->pluck('id')->all(), $first));
    }

    public function test_a_new_member_still_gets_a_mix(): void
    {
        collect(range(1, 5))->each(fn ($i) => $this->track("A$i", "T$i"));

        $this->assertCount(5, $this->actingAs(User::factory()->create())->getJson('/api/mixes/daily')->assertOk()->json('data'));
    }
}
