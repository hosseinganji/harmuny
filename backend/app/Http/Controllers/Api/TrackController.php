<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\TrackResource;
use App\Models\Play;
use App\Models\Playlist;
use App\Models\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Support\TextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrackController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'sort' => ['nullable', 'in:recent,oldest,title,artist,popular,duration'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Track::query()
            ->search($request->query('q'))
            ->when($request->query('artist'), fn ($q, $artist) => $q->where('artist', $artist))
            ->when($request->query('channel'), fn ($q, $id) => $q->where('channel_id', $id));

        match ($request->query('sort', 'recent')) {
            'oldest' => $query->orderBy('posted_at'),
            'title' => $query->orderBy('title'),
            'artist' => $query->orderBy('artist')->orderBy('title'),
            'popular' => $query->orderByDesc('play_count'),
            'duration' => $query->orderByDesc('duration'),
            default => $query->orderByDesc('posted_at')->orderByDesc('id'),
        };

        return TrackResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function show(Track $track): TrackResource
    {
        return new TrackResource($track);
    }

    /**
     * Delta feed of the whole catalog for the offline library.
     * Returns every track changed since `since`, including deleted ones.
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate(['since' => ['nullable', 'date']]);
        $since = $request->query('since') ? Carbon::parse($request->query('since')) : null;
        $serverTime = now();

        $tracks = Track::withTrashed()
            ->when($since, fn ($q) => $q->where('updated_at', '>', $since), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('id')
            ->get();

        return response()->json([
            'server_time' => $serverTime->toIso8601String(),
            'full' => ! $since,
            // Plays don't touch updated_at (it versions artwork), so counts travel separately, always in full.
            'play_counts' => Track::query()->where('play_count', '>', 0)->pluck('play_count', 'id'),
            'play_badge_min' => config('music.play_count_badge_min'),
            'tracks' => TrackResource::collection($tracks->reject->trashed()->values()),
            'deleted' => $tracks->filter->trashed()->pluck('id')->values(),
        ]);
    }

    public function artists(Request $request): JsonResponse
    {
        return response()->json(['data' => self::artistsMatching($request->query('q'))]);
    }

    /** Artists with track counts, optionally filtered by a normalized name match. */
    public static function artistsMatching(?string $term, ?int $limit = null): Collection
    {
        $words = TextNormalizer::words($term);

        return Track::query()
            ->whereNotNull('artist')
            ->select('artist', DB::raw('count(*) as tracks_count'), DB::raw('sum(play_count) as plays'))
            ->selectRaw('max(case when has_cover = 1 then id end) as cover_track_id')
            ->groupBy('artist')
            ->orderByDesc('tracks_count')
            ->get()
            ->filter(function ($row) use ($words) {
                $name = TextNormalizer::normalize($row->artist);
                // "song title + artist" queries: the whole artist name appears in the query.
                if ($words && $name !== '' && str_contains(' '.implode(' ', $words).' ', ' '.$name.' ')) {
                    return true;
                }
                foreach ($words as $word) {
                    if (! str_contains($name, $word)) {
                        return false;
                    }
                }

                return true;
            })
            ->when($limit, fn ($c) => $c->take($limit))
            ->values();
    }

    public function artist(Request $request): JsonResponse
    {
        $name = (string) $request->query('name');
        $tracks = Track::query()->where('artist', $name)->orderByDesc('play_count')->orderByDesc('posted_at')->get();
        abort_if($tracks->isEmpty(), 404);

        return response()->json([
            'name' => $name,
            'tracks_count' => $tracks->count(),
            'duration' => (int) $tracks->sum('duration'),
            'cover_track_id' => $tracks->firstWhere('has_cover', true)?->id,
            'tracks' => TrackResource::collection($tracks),
        ]);
    }

    public function home(Request $request): JsonResponse
    {
        $user = $request->user();

        $recentIds = Play::query()
            ->where('user_id', $user->id)
            ->select('track_id', DB::raw('max(played_at) as last_played'))
            ->groupBy('track_id')
            ->orderByDesc('last_played')
            ->limit(20)
            ->pluck('track_id');

        $topIds = Play::query()
            ->where('user_id', $user->id)
            ->where('played_at', '>=', now()->subDays(30))
            ->select('track_id', DB::raw('count(*) as c'))
            ->groupBy('track_id')
            ->orderByDesc('c')
            ->limit(20)
            ->pluck('track_id');

        $byIds = fn ($ids) => TrackResource::collection(
            Track::query()->whereIn('id', $ids)->get()->sortBy(fn ($t) => $ids->search($t->id))->values()
        );

        $playlists = Playlist::query()
            ->with('user')
            ->withCount('tracks')
            ->where('is_public', true)
            ->where('user_id', '!=', $user->id)
            ->latest('updated_at')
            ->limit(12)
            ->get();
        PlaylistController::attachPreviews($playlists);

        return response()->json([
            'recently_added' => TrackResource::collection(Track::query()->latest('posted_at')->limit(20)->get()),
            'recently_played' => $byIds($recentIds),
            'your_top' => $byIds($topIds),
            'popular' => TrackResource::collection(Track::query()->where('play_count', '>', 0)->orderByDesc('play_count')->limit(20)->get()),
            'discover' => TrackResource::collection(Track::query()->whereNotIn('id', $recentIds)->inRandomOrder()->limit(20)->get()),
            'community_playlists' => PlaylistResource::collection($playlists),
            'total_tracks' => Track::query()->count(),
        ]);
    }
}
