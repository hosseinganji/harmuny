<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LikeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tracks = $request->user()->likedTracks()->orderByPivot('created_at', 'desc')->get();

        return TrackResource::collection($tracks);
    }

    public function store(Request $request, Track $track): JsonResponse
    {
        $request->user()->likedTracks()->syncWithoutDetaching([$track->id => ['created_at' => now()]]);
        TrackResource::forgetLikes($request->user()->id);

        return response()->json(['liked' => true]);
    }

    public function destroy(Request $request, Track $track): JsonResponse
    {
        $request->user()->likedTracks()->detach($track->id);
        TrackResource::forgetLikes($request->user()->id);

        return response()->json(['liked' => false]);
    }
}
