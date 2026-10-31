<?php

namespace App\Services;

use App\Jobs\PushToUsersJob;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\User;

/** Song requests: admins hear about new ones; a song arriving that answers one tells whoever asked. */
class SongRequests
{
    public static function created(SongRequest $request): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->pluck('id')->all();
        $song = trim(($request->artist ? $request->artist.' – ' : '').$request->title);
        $name = $request->user->name;
        PushToUsersJob::dispatch($admins, [
            'fa' => ['title' => '🙋 درخواست آهنگ از '.$name, 'body' => $song, 'url' => '/requests', 'tag' => 'request-'.$request->id],
            'en' => ['title' => '🙋 Song request from '.$name, 'body' => $song, 'url' => '/requests', 'tag' => 'request-'.$request->id],
        ]);
    }

    /** A song just arrived: requests it answers are fulfilled, and their members told. */
    public static function fulfil(Track $track): void
    {
        $pending = SongRequest::query()->where('status', SongRequest::PENDING)->get();
        foreach ($pending as $request) {
            if (self::answers($track, $request)) {
                self::markFulfilled($request, $track, null);
            }
        }
    }

    public static function markFulfilled(SongRequest $request, Track $track, ?User $by): void
    {
        $request->update(['status' => SongRequest::FULFILLED, 'track_id' => $track->id, 'handled_by' => $by?->id, 'handled_at' => now()]);
        $song = trim(($track->artist ? $track->artist.' – ' : '').$track->title);
        PushToUsersJob::dispatch([$request->user_id], [
            'fa' => ['title' => '🎉 آهنگی که خواسته بودی اضافه شد', 'body' => $song, 'url' => '/requests', 'tag' => 'request-'.$request->id],
            'en' => ['title' => '🎉 The song you asked for is here', 'body' => $song, 'url' => '/requests', 'tag' => 'request-'.$request->id],
        ]);
    }

    /** Same title (normalized), and the artist too when the request names one. */
    public static function answers(Track $track, SongRequest $request): bool
    {
        [, $wantedTitle] = explode('|', Track::matchKey(null, $request->title), 2);
        [$trackArtist, $trackTitle] = explode('|', (string) $track->match_key, 2) + [1 => ''];
        if ($wantedTitle === '' || $trackTitle !== $wantedTitle) {
            return false;
        }
        [$wantedArtist] = explode('|', Track::matchKey($request->artist, ''), 2);

        return $wantedArtist === '' || str_contains($trackArtist, $wantedArtist) || str_contains($wantedArtist, $trackArtist);
    }
}
