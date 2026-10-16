<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Playlist */
class PlaylistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_public' => $this->is_public,
            'cover_url' => $this->coverUrl(),
            'owner' => [
                'id' => $this->user_id,
                'name' => $this->whenLoaded('user', fn () => $this->user->name),
                'username' => $this->whenLoaded('user', fn () => $this->user->username),
            ],
            'is_owner' => $request->user()?->id === $this->user_id,
            'tracks_count' => $this->tracks_count ?? $this->whenLoaded('tracks', fn () => $this->tracks->count()),
            'duration' => $this->whenLoaded('tracks', fn () => (int) $this->tracks->sum('duration')),
            // Up to four track ids for a mosaic cover when no custom cover is set.
            'preview_track_ids' => $this->preview_track_ids ?? [],
            'tracks' => TrackResource::collection($this->whenLoaded('tracks')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
