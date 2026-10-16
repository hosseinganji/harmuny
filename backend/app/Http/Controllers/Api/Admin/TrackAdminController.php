<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Services\CoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackAdminController extends Controller
{
    public function update(Request $request, Track $track): TrackResource
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'artist' => ['sometimes', 'nullable', 'string', 'max:250'],
            'album' => ['sometimes', 'nullable', 'string', 'max:250'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:100'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:2200'],
        ]);
        $track->fill($data);
        $track->metadata_locked = true;
        $track->save();

        return new TrackResource($track);
    }

    public function uploadCover(Request $request, Track $track, CoverService $covers): TrackResource
    {
        $request->validate(['cover' => ['required', 'image', 'max:5120']]);
        $covers->store($track, $request->file('cover')->get());
        $track->touch();

        return new TrackResource($track);
    }

    public function destroy(Track $track): JsonResponse
    {
        $track->delete();

        return response()->json(['ok' => true]);
    }
}
