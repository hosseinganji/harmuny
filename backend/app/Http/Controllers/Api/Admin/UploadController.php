<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Services\TrackImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admins add songs straight to the library; the files stay on this server. */
class UploadController extends Controller
{
    public function store(Request $request, TrackImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(config('music.upload_max_mb') * 1024), 'mimes:mp3,m4a,mp4,aac,flac,ogg,oga,opus,wav,webm'],
            'title' => ['nullable', 'string', 'max:250'],
            'artist' => ['nullable', 'string', 'max:250'],
            'album' => ['nullable', 'string', 'max:250'],
        ]);
        $file = $data['file'];
        $temp = storage_path('app/private/upload-tmp/'.uniqid('', true).'.'.$file->getClientOriginalExtension());
        $file->move(dirname($temp), basename($temp));

        ['track' => $track, 'duplicate' => $duplicate] = $importer->import($temp, Track::SOURCE_UPLOAD, [
            'title' => $data['title'] ?? null,
            'artist' => $data['artist'] ?? null,
            'album' => $data['album'] ?? null,
            'file_name' => $file->getClientOriginalName(),
        ], $request->user());

        return response()->json([
            'duplicate' => $duplicate,
            'data' => (new TrackResource($track))->resolve($request),
        ], $duplicate ? 200 : 201);
    }
}
