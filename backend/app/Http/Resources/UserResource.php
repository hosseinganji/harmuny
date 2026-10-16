<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $private = $viewer && ($viewer->id === $this->id || $viewer->isAdmin());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatarUrl(),
            'bio' => $this->bio,
            'role' => $this->role,
            'email' => $this->when($private, $this->email),
            'status' => $this->when($private, $this->status),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_seen_at' => $this->when($private, fn () => $this->last_seen_at?->toIso8601String()),
            'approved_at' => $this->when($private, fn () => $this->approved_at?->toIso8601String()),
        ];
    }
}
