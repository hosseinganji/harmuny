<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Following artists: their new songs show first and come with a notification. */
class ArtistFollowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('artist_follows')->where('user_id', $request->user()->id)->orderBy('artist')->pluck('artist')]);
    }

    public function store(Request $request): JsonResponse
    {
        $artist = $request->validate(['artist' => ['required', 'string', 'max:191']])['artist'];
        DB::table('artist_follows')->insertOrIgnore(['user_id' => $request->user()->id, 'artist' => $artist, 'created_at' => now()]);

        return $this->index($request);
    }

    public function destroy(Request $request): JsonResponse
    {
        $artist = $request->validate(['artist' => ['required', 'string', 'max:191']])['artist'];
        DB::table('artist_follows')->where('user_id', $request->user()->id)->where('artist', $artist)->delete();

        return $this->index($request);
    }
}
