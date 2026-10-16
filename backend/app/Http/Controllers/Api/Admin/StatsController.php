<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Play;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\TrackCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function __invoke(TrackCache $cache): JsonResponse
    {
        return response()->json([
            'users' => User::query()->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status'),
            'tracks' => Track::query()->count(),
            'library_bytes' => (int) Track::query()->sum('file_size'),
            'library_seconds' => (int) Track::query()->sum('duration'),
            'channels' => Channel::query()->count(),
            'playlists' => Playlist::query()->count(),
            'plays_today' => Play::query()->where('played_at', '>=', today())->count(),
            'plays_week' => Play::query()->where('played_at', '>=', now()->subWeek())->count(),
            'active_today' => User::query()->where('last_seen_at', '>=', today())->count(),
            'cache' => $cache->usage(),
            'cached_tracks' => Track::query()->whereNotNull('cache_path')->count(),
            'pending_downloads' => DB::table('jobs')->where('queue', 'downloads')->count(),
        ]);
    }
}
