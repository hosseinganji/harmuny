<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Who has the app open right now. Calling it also counts as a heartbeat (see EnsureActive). */
class OnlineController extends Controller
{
    /** Clients ping every 45 seconds while open, so two minutes tolerates a missed ping. */
    public const WINDOW_SECONDS = 120;

    public function __invoke(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where('last_seen_at', '>=', now()->subSeconds(self::WINDOW_SECONDS))
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatarUrl(),
                'is_me' => $user->is($request->user()),
            ])->values(),
        ]);
    }
}
