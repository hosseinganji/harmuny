<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Play;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plays = Play::query()
            ->with('track')
            ->where('user_id', $request->user()->id)
            ->whereHas('track')
            ->latest('played_at')
            ->paginate(50);

        return response()->json([
            'data' => $plays->getCollection()->map(fn (Play $play) => [
                'id' => $play->id,
                'played_at' => $play->played_at->toIso8601String(),
                'seconds' => $play->seconds,
                'track' => (new TrackResource($play->track))->resolve($request),
            ]),
            'meta' => ['current_page' => $plays->currentPage(), 'last_page' => $plays->lastPage()],
        ]);
    }

    /**
     * Record listens. Accepts a batch so offline plays can be uploaded later.
     * A listen counts once at least 30 seconds (or half of a short track) was heard.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plays' => ['required', 'array', 'max:500'],
            'plays.*.track_id' => ['required', 'integer'],
            'plays.*.seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'plays.*.played_at' => ['nullable', 'date'],
        ]);

        $tracks = Track::query()->whereIn('id', collect($data['plays'])->pluck('track_id'))->get()->keyBy('id');
        $recorded = 0;

        foreach ($data['plays'] as $play) {
            $track = $tracks->get($play['track_id']);
            if (! $track || $play['seconds'] < min(30, max(1, intdiv((int) $track->duration, 2)))) {
                continue;
            }
            Play::query()->create([
                'user_id' => $request->user()->id,
                'track_id' => $track->id,
                'seconds' => $play['seconds'],
                'played_at' => $play['played_at'] ?? now(),
            ]);
            // Bypass timestamps: updated_at versions artwork and drives the catalog delta feed.
            DB::table('tracks')->where('id', $track->id)->increment('play_count');
            $recorded++;
        }

        return response()->json(['recorded' => $recorded]);
    }

    public function clear(Request $request): JsonResponse
    {
        Play::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }
}
