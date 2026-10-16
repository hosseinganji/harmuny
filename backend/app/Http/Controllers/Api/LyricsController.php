<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lyric;
use App\Models\Track;
use App\Services\LyricsService;
use App\Services\TrackCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LyricsController extends Controller
{
    public function track(Track $track, LyricsService $lyrics, TrackCache $cache): JsonResponse
    {
        $found = $lyrics->find($track->title, $track->artist, $track->album, (int) $track->duration);
        if ($found) {
            return $this->respond($found);
        }

        $embedded = $cache->embeddedLyrics($track);

        return response()->json($embedded
            ? ['synced' => null, 'plain' => $embedded, 'instrumental' => false, 'source' => 'file']
            : ['synced' => null, 'plain' => null, 'instrumental' => false, 'source' => null]);
    }

    /** Lyrics for arbitrary metadata, used by on-device tracks. */
    public function lookup(Request $request, LyricsService $lyrics): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'artist' => ['nullable', 'string', 'max:250'],
            'album' => ['nullable', 'string', 'max:250'],
            'duration' => ['nullable', 'integer', 'min:0'],
        ]);

        $found = $lyrics->find($data['title'], $data['artist'] ?? null, $data['album'] ?? null, (int) ($data['duration'] ?? 0));

        return $found
            ? $this->respond($found)
            : response()->json(['synced' => null, 'plain' => null, 'instrumental' => false, 'source' => null]);
    }

    private function respond(Lyric $lyric): JsonResponse
    {
        return response()->json([
            'synced' => $lyric->synced,
            'plain' => $lyric->plain,
            'instrumental' => $lyric->instrumental,
            'source' => $lyric->source,
        ]);
    }
}
