<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function track(array $attrs = []): Track
    {
        $channel = Channel::query()->firstOrCreate(['peer' => '@music'], ['telegram_id' => -1001, 'title' => 'Music']);
        static $msg = 1;

        return Track::query()->create([
            'channel_id' => $channel->id, 'message_id' => $msg++, 'title' => 'Song', 'duration' => 200,
            'file_size' => 1000, 'mime_type' => 'audio/mpeg', 'posted_at' => now(), ...$attrs,
        ]);
    }

    private function play(User $user, Track $track, string $localTime, int $seconds = 120): void
    {
        Play::query()->create([
            'user_id' => $user->id, 'track_id' => $track->id, 'seconds' => $seconds,
            'played_at' => CarbonImmutable::parse($localTime, 'Asia/Tehran')->utc(),
        ]);
    }

    public function test_reports_are_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/reports?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }

    public function test_totals_timeline_and_previous_period_use_local_days(): void
    {
        $admin = User::factory()->admin()->create();
        $sara = User::factory()->create();
        $a = $this->track(['title' => 'A', 'artist' => 'Shayea', 'genre' => 'Rap']);
        $b = $this->track(['title' => 'B', 'artist' => 'Tataloo']);

        // 00:30 on the 10th in Tehran is still the 9th in UTC: it must count on the 10th.
        $this->play($admin, $a, '2026-10-10 00:30');
        $this->play($sara, $a, '2026-10-10 21:00');
        $this->play($sara, $b, '2026-10-11 09:00', 300);
        $this->play($sara, $b, '2026-10-03 10:00'); // the week before

        $res = $this->actingAs($admin)->getJson('/api/admin/reports?from=2026-10-10&to=2026-10-16')->assertOk();

        $res->assertJsonPath('bucket', 'day')
            ->assertJsonPath('summary.plays', 3)
            ->assertJsonPath('summary.listeners', 2)
            ->assertJsonPath('summary.minutes', 9)
            ->assertJsonPath('previous.plays', 1)
            ->assertJsonPath('timeline.0.key', '2026-10-10')
            ->assertJsonPath('timeline.0.plays', 2)
            ->assertJsonPath('timeline.1.key', '2026-10-11')
            ->assertJsonPath('top_tracks.0.track.title', 'A')
            ->assertJsonPath('top_artists.0.name', 'Shayea')
            ->assertJsonPath('top_genres.0.name', 'Rap')
            ->assertJsonPath('top_listeners.0.user.id', $sara->id);
        $this->assertSame(1, $res->json('by_hour.0'));
        $this->assertSame(1, $res->json('by_hour.21'));
    }

    public function test_a_single_day_is_hourly(): void
    {
        $admin = User::factory()->admin()->create();
        $this->play($admin, $this->track(), '2026-10-10 14:10');

        $this->actingAs($admin)->getJson('/api/admin/reports?from=2026-10-10&to=2026-10-10')
            ->assertOk()->assertJsonPath('bucket', 'hour')->assertJsonPath('timeline.0.key', '14');
    }

    public function test_sync_carries_play_counts_and_the_badge_threshold(): void
    {
        config(['music.play_count_badge_min' => 7]);
        $track = $this->track();
        $track->forceFill(['play_count' => 12])->save();

        $this->actingAs(User::factory()->create())->getJson('/api/library/sync')
            ->assertOk()->assertJsonPath("play_counts.{$track->id}", 12)->assertJsonPath('play_badge_min', 7);
    }
}
