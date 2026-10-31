<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Services\RadioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RadioController extends Controller
{
    /** Endless radio: the app asks for more, passing what is already queued. */
    public function radio(Request $request, RadioService $radio): JsonResponse
    {
        $data = $request->validate([
            'track_id' => ['nullable', 'integer', 'exists:tracks,id', 'required_without:artist'],
            'artist' => ['nullable', 'string', 'max:250', 'required_without:track_id'],
            'exclude' => ['array', 'max:2000'],
            'exclude.*' => ['integer'],
            // Follow-up pages don't repeat the seed song.
            'more' => ['boolean'],
        ]);
        $seed = isset($data['track_id']) ? Track::query()->find($data['track_id']) : null;
        $tracks = $radio->radio(
            ($data['more'] ?? false) ? null : $seed,
            $data['artist'] ?? $seed?->artist,
            array_merge($data['exclude'] ?? [], $seed ? [$seed->id] : []),
        );

        return response()->json(['data' => TrackResource::collection($tracks)->resolve($request)]);
    }

    public function dailyMix(Request $request, RadioService $radio): JsonResponse
    {
        return response()->json(['data' => TrackResource::collection($radio->dailyMix($request->user()))->resolve($request)]);
    }

    /**
     * Release Radar: what came out in the last two weeks by the artists this member follows, likes or plays,
     * newest first; topped up with everything new when that is too little.
     */
    public function releaseRadar(Request $request): JsonResponse
    {
        $user = $request->user();
        $artists = \Illuminate\Support\Facades\DB::table('artist_follows')->where('user_id', $user->id)->pluck('artist')
            ->merge(Track::query()->join('track_likes', 'track_likes.track_id', '=', 'tracks.id')->where('track_likes.user_id', $user->id)->whereNotNull('tracks.artist')->distinct()->pluck('tracks.artist'))
            ->merge(\App\Models\Play::query()->join('tracks', 'tracks.id', '=', 'plays.track_id')->where('plays.user_id', $user->id)->where('plays.played_at', '>=', now()->subDays(90))->whereNotNull('tracks.artist')->distinct()->pluck('tracks.artist'))
            ->unique()->values();
        $since = now()->subDays(14);
        $mine = Track::query()->where('posted_at', '>=', $since)->whereIn('artist', $artists)->latest('posted_at')->limit(30)->get();
        $rest = $mine->count() >= 15 ? collect() : Track::query()->where('posted_at', '>=', $since)->whereNotIn('id', $mine->pluck('id'))->latest('posted_at')->limit(30 - $mine->count())->get();

        return response()->json([
            'data' => TrackResource::collection($mine->merge($rest))->resolve($request),
            'personal' => $mine->count(),
        ]);
    }

    /** Smart Shuffle: songs this member will likely enjoy, to mix into a shuffled queue. */
    public function smartShuffle(Request $request, RadioService $radio): JsonResponse
    {
        $data = $request->validate([
            'exclude' => ['array', 'max:5000'],
            'exclude.*' => ['integer'],
            'limit' => ['integer', 'min:1', 'max:100'],
        ]);
        $tracks = $radio->forYou($request->user(), $data['exclude'] ?? [], $data['limit'] ?? 20);

        return response()->json(['data' => TrackResource::collection($tracks)->resolve($request)]);
    }
}
