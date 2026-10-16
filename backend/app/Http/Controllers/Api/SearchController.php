<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\TrackResource;
use App\Models\Playlist;
use App\Models\Track;
use App\Support\TextNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (TextNormalizer::words($q) === []) {
            return response()->json(['tracks' => [], 'artists' => [], 'playlists' => []]);
        }

        $tracks = Track::query()->search($q)->orderByDesc('play_count')->orderByDesc('posted_at')->limit(50)->get();

        $artists = TrackController::artistsMatching($q, 10);

        $userId = $request->user()->id;
        $playlists = Playlist::query()
            ->with('user')
            ->withCount('tracks')
            ->where(fn ($query) => $query->where('user_id', $userId)->orWhere('is_public', true))
            ->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')
            ->limit(10)
            ->get();
        PlaylistController::attachPreviews($playlists);

        return response()->json([
            'tracks' => TrackResource::collection($tracks),
            'artists' => $artists,
            'playlists' => PlaylistResource::collection($playlists),
        ]);
    }
}
