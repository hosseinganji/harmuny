<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who has the app open right now, and what they are listening to. Calling it also counts as a
 * heartbeat (see EnsureActive); the app reports its current song with `presence`.
 */
class OnlineController extends Controller
{
    /** Clients ping every 45 seconds while open, so two minutes tolerates a missed ping. */
    public const WINDOW_SECONDS = 120;

    public function __invoke(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('nowPlaying')
            ->where('status', User::STATUS_ACTIVE)
            ->where('last_seen_at', '>=', now()->subSeconds(self::WINDOW_SECONDS))
            ->orderByDesc('last_seen_at')
            ->get();
        $fresh = now()->subSeconds(self::WINDOW_SECONDS);

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatarUrl(),
                'is_me' => $user->is($request->user()),
                'now_playing' => $user->nowPlaying && $user->now_playing_at?->gte($fresh)
                    ? (new TrackResource($user->nowPlaying))->resolve($request)
                    : null,
            ])->values(),
        ]);
    }

    /** The app reports the song it is playing (null when paused or playing a file from the device). */
    public function presence(Request $request): JsonResponse
    {
        $data = $request->validate(['track_id' => ['nullable', 'integer', 'exists:tracks,id']]);
        $request->user()->forceFill([
            'now_playing_track_id' => $data['track_id'] ?? null,
            'now_playing_at' => isset($data['track_id']) ? now() : null,
            'last_seen_at' => now(),
        ])->saveQuietly();

        return response()->json(['ok' => true]);
    }
}
