<?php

namespace App\Services;

use App\Jobs\NotifyNewTracksJob;
use App\Models\Track;
use App\Models\User;
use App\Services\Telegram\MessageParser;
use getID3;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Adds an audio file kept on this server to the library: an admin's upload, or a song the daily discovery
 * found. Tags fill in what the caller didn't say; the same song already in the library is never added twice.
 */
class TrackImporter
{
    public function __construct(private CoverService $covers) {}

    /**
     * @param  string  $path  a file the importer may move or delete
     * @param  array{title?: ?string, artist?: ?string, album?: ?string, file_name?: ?string, duration?: int, posted_at?: \DateTimeInterface, source_url?: ?string, external_id?: ?string, cover?: ?string}  $meta
     * @return array{track: Track, duplicate: bool}
     */
    public function import(string $path, string $source, array $meta = [], ?User $by = null): array
    {
        $info = [];
        try {
            $info = (new getID3)->analyze($path);
            \getid3_lib::CopyTagsToComments($info);
        } catch (\Throwable) {
            // Unreadable tags: the caller's details and the file name have to do.
        }
        $tag = fn (string $key) => MessageParser::clean(isset($info['comments'][$key][0]) ? (string) $info['comments'][$key][0] : null);
        $fileName = $meta['file_name'] ?? basename($path);
        [$guessTitle, $guessArtist] = MessageParser::guessFromFileName($fileName);

        $title = MessageParser::clean($meta['title'] ?? null) ?? $tag('title') ?? $guessTitle ?? 'Untitled';
        $artist = MessageParser::clean($meta['artist'] ?? null) ?? $tag('artist') ?? $guessArtist;
        $duration = (int) ($meta['duration'] ?? 0) ?: (int) round($info['playtime_seconds'] ?? 0);

        if ($same = Track::findDuplicate($artist, $title, $duration)) {
            File::delete($path);

            return ['track' => $same, 'duplicate' => true];
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) ?: ($info['fileformat'] ?? 'mp3');
        $relative = "library/$source/".now()->format('Y-m').'/'.Str::uuid().'.'.$ext;
        File::ensureDirectoryExists(dirname(Storage::path($relative)));
        File::move($path, Storage::path($relative));
        $year = (int) ($tag('year') ?? 0);

        $track = Track::query()->create([
            'source' => $source,
            'source_url' => $meta['source_url'] ?? null,
            'external_id' => $meta['external_id'] ?? null,
            'added_by' => $by?->id,
            'title' => $title,
            'artist' => $artist,
            'album' => MessageParser::clean($meta['album'] ?? null) ?? $tag('album'),
            'genre' => $tag('genre'),
            'year' => $year > 1800 && $year < 2200 ? $year : null,
            'duration' => $duration,
            'file_name' => $fileName,
            'mime_type' => $info['mime_type'] ?? null,
            'file_size' => filesize(Storage::path($relative)),
            'has_cover' => false,
            'posted_at' => $meta['posted_at'] ?? now(),
        ]);
        $track->forceFill(['cache_path' => $relative, 'cached_at' => now(), 'cache_used_at' => now()])->saveQuietly();

        $cover = $meta['cover'] ?? ($info['comments']['picture'][0]['data'] ?? null);
        if ($cover) {
            $this->covers->store($track, $cover);
        }
        NotifyNewTracksJob::collect($track);

        return ['track' => $track->refresh(), 'duplicate' => false];
    }
}
