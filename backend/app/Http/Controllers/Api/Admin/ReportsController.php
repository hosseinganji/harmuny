<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Channel;
use App\Models\Play;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Listening reports for admins over any date range (inclusive, in the reports timezone).
 *
 * The app picks the periods (day, week, month, season, year, in the Persian or Gregorian calendar),
 * so this only answers "what happened between these days": daily figures (hourly for a single day)
 * that the app groups as it needs, the same totals for the equally long period before, and top lists.
 */
class ReportsController extends Controller
{
    private const TOP = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tz = config('music.reports_timezone');
        $from = CarbonImmutable::parse($data['from'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'], $tz)->endOfDay();
        abort_if($from->diffInDays($to) > 400, 422, 'Choose a range of at most a year.');

        $days = (int) round($from->diffInDays($to->startOfDay())); // 0 for a single day
        $prevFrom = $from->subDays($days + 1);
        $prevTo = $from->subSecond();
        // Iran has had a fixed offset since 2022; shift timestamps by it so SQL buckets by local day/hour.
        $offset = $from->getOffset();
        [$start, $end] = [$from->utc(), $to->utc()];

        return response()->json([
            'timezone' => $tz,
            'bucket' => $days === 0 ? 'hour' : 'day',
            'summary' => $this->summary($from->utc(), $to->utc()),
            'previous' => $this->summary($prevFrom->utc(), $prevTo->utc()),
            'timeline' => $this->timeline($start, $end, $offset, $days === 0),
            'by_hour' => $this->byHour($start, $end, $offset),
            'by_weekday' => $this->byWeekday($start, $end, $offset),
            'top_tracks' => $this->topTracks($start, $end, $request),
            'top_artists' => $this->topBy('tracks.artist', $start, $end),
            'top_genres' => $this->topBy('tracks.genre', $start, $end),
            'top_listeners' => $this->topListeners($start, $end),
            'library' => [
                'tracks' => Track::query()->count(),
                'never_played' => Track::query()->where('play_count', 0)->count(),
                'on_server' => Track::query()->whereNotNull('cache_path')->count(),
                'bytes' => (int) Track::query()->sum('file_size'),
                'seconds' => (int) Track::query()->sum('duration'),
                'channels' => Channel::query()->count(),
                'liked_tracks' => (int) DB::table('track_likes')->distinct()->count('track_id'),
            ],
            'users' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('status', User::STATUS_ACTIVE)->count(),
                'pending' => User::query()->where('status', User::STATUS_PENDING)->count(),
                'online_now' => User::query()->where('last_seen_at', '>=', now()->subMinutes(2))->count(),
            ],
        ]);
    }

    /** Headline totals for one period. */
    private function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $plays = Play::query()->whereBetween('played_at', [$from, $to]);
        $count = (clone $plays)->count();
        $seconds = (int) (clone $plays)->sum('seconds');
        $listeners = (clone $plays)->distinct()->count('user_id');

        return [
            'plays' => $count,
            'minutes' => intdiv($seconds, 60),
            'listeners' => $listeners,
            'minutes_per_listener' => $listeners ? round($seconds / 60 / $listeners) : 0,
            'songs_played' => (clone $plays)->distinct()->count('track_id'),
            'new_users' => User::query()->whereBetween('created_at', [$from, $to])->count(),
            'new_tracks' => Track::query()->whereBetween('created_at', [$from, $to])->count(),
            'likes' => DB::table('track_likes')->whereBetween('created_at', [$from, $to])->count(),
            'playlists' => Playlist::query()->whereBetween('created_at', [$from, $to])->count(),
        ];
    }

    private function local(string $column, int $offset): string
    {
        return "DATE_ADD({$column}, INTERVAL {$offset} SECOND)";
    }

    /** Plays, minutes and listeners per local day (or per hour for a single day). */
    private function timeline(CarbonImmutable $from, CarbonImmutable $to, int $offset, bool $hourly): array
    {
        $key = $hourly ? 'HOUR('.$this->local('played_at', $offset).')' : 'DATE('.$this->local('played_at', $offset).')';

        return Play::query()
            ->whereBetween('played_at', [$from, $to])
            ->selectRaw("{$key} as k, COUNT(*) as plays, COALESCE(SUM(seconds), 0) div 60 as minutes, COUNT(DISTINCT user_id) as listeners")
            ->groupBy('k')
            ->orderBy('k')
            ->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'plays' => (int) $r->plays, 'minutes' => (int) $r->minutes, 'listeners' => (int) $r->listeners])
            ->all();
    }

    /** Plays per local hour of day, 0–23. */
    private function byHour(CarbonImmutable $from, CarbonImmutable $to, int $offset): array
    {
        $rows = Play::query()
            ->whereBetween('played_at', [$from, $to])
            ->selectRaw('HOUR('.$this->local('played_at', $offset).') as h, COUNT(*) as c')
            ->groupBy('h')
            ->pluck('c', 'h');

        return array_map(fn ($h) => (int) ($rows[$h] ?? 0), range(0, 23));
    }

    /** Plays per local weekday, ISO order (index 0 = Monday … 6 = Sunday). */
    private function byWeekday(CarbonImmutable $from, CarbonImmutable $to, int $offset): array
    {
        $rows = Play::query()
            ->whereBetween('played_at', [$from, $to])
            ->selectRaw('WEEKDAY('.$this->local('played_at', $offset).') as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        return array_map(fn ($d) => (int) ($rows[$d] ?? 0), range(0, 6));
    }

    private function topTracks(CarbonImmutable $from, CarbonImmutable $to, Request $request): array
    {
        $rows = Play::query()
            ->whereBetween('played_at', [$from, $to])
            ->selectRaw('track_id, COUNT(*) as plays, COUNT(DISTINCT user_id) as listeners')
            ->groupBy('track_id')
            ->orderByDesc('plays')
            ->limit(self::TOP)
            ->get();
        $tracks = Track::query()->whereIn('id', $rows->pluck('track_id'))->get()->keyBy('id');

        return $rows->filter(fn ($r) => $tracks->has($r->track_id))->map(fn ($r) => [
            'track' => (new TrackResource($tracks[$r->track_id]))->resolve($request),
            'plays' => (int) $r->plays,
            'listeners' => (int) $r->listeners,
        ])->values()->all();
    }

    /** Top values of a track column (artist, genre) by plays. */
    private function topBy(string $column, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereBetween('plays.played_at', [$from, $to])
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw("{$column} as name, COUNT(*) as plays, COUNT(DISTINCT plays.user_id) as listeners, COALESCE(SUM(plays.seconds), 0) div 60 as minutes")
            ->groupBy('name')
            ->orderByDesc('plays')
            ->limit(self::TOP)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'plays' => (int) $r->plays, 'listeners' => (int) $r->listeners, 'minutes' => (int) $r->minutes])
            ->all();
    }

    private function topListeners(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Play::query()
            ->whereBetween('played_at', [$from, $to])
            ->selectRaw('user_id, COUNT(*) as plays, COALESCE(SUM(seconds), 0) div 60 as minutes')
            ->groupBy('user_id')
            ->orderByDesc('minutes')
            ->limit(self::TOP)
            ->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows->filter(fn ($r) => $users->has($r->user_id))->map(fn ($r) => [
            'user' => ['id' => $r->user_id, 'name' => $users[$r->user_id]->name, 'username' => $users[$r->user_id]->username, 'avatar_url' => $users[$r->user_id]->avatarUrl()],
            'plays' => (int) $r->plays,
            'minutes' => (int) $r->minutes,
        ])->values()->all();
    }
}
