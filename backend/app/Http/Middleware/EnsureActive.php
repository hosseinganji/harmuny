<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isActive()) {
            $user?->currentAccessToken()?->delete();

            return response()->json(['message' => 'Your account is not active.', 'status' => $user?->status], 403);
        }

        // Feeds the online list, so keep it within a minute.
        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
