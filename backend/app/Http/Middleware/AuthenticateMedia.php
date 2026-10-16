<?php

namespace App\Http\Middleware;

use App\Support\MediaKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Accepts either a Sanctum bearer token or a ?k= media key. */
class AuthenticateMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user() ?? MediaKey::resolve($request->query('k'));

        if (! $user || ! $user->isActive()) {
            abort(401);
        }
        Auth::setUser($user);

        return $next($request);
    }
}
