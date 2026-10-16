<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin \App\Models\Track */
class TrackResource extends JsonResource
{
    /** @var array<int, array<int, true>> liked track ids per user, loaded once per request */
    private static array $likedCache = [];

    public static function likedIds(int $userId): array
    {
        return self::$likedCache[$userId] ??= array_fill_keys(
            DB::table('track_likes')->where('user_id', $userId)->pluck('track_id')->all(),
            true
        );
    }

    public static function forgetLikes(int $userId): void
    {
        unset(self::$likedCache[$userId]);
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist,
            'album' => $this->album,
            'genre' => $this->genre,
            'year' => $this->year,
            'duration' => $this->duration,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'has_cover' => $this->has_cover,
            // Stored on this server, so devices can download it without the server touching Telegram.
            'on_server' => $this->cache_path !== null,
            'play_count' => $this->play_count,
            'channel_id' => $this->channel_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'liked' => $user ? isset(self::likedIds($user->id)[$this->id]) : false,
            'entry_id' => $this->whenPivotLoaded('playlist_track', fn () => $this->pivot->id),
            'deleted' => $this->when($this->trashed(), true),
        ];
    }
}
