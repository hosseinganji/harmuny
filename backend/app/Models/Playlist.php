<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Playlist extends Model
{
    protected $fillable = ['name', 'description', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)
            ->withPivot(['id', 'position', 'added_by', 'created_at'])
            ->orderByPivot('position');
    }

    /** Members the owner invited to add, remove and reorder songs. */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'playlist_collaborators')->withPivot('created_at');
    }

    public function isCollaborator(User $user): bool
    {
        return $this->relationLoaded('collaborators')
            ? $this->collaborators->contains('id', $user->id)
            : $this->collaborators()->whereKey($user->id)->exists();
    }

    /** Owner or collaborator: may change which songs are in it and their order. */
    public function canEditTracks(User $user): bool
    {
        return $this->user_id === $user->id || $this->isCollaborator($user);
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->user_id === $user->id || $this->is_public || $this->isCollaborator($user);
    }

    public function coverUrl(): ?string
    {
        if (! $this->cover_path) {
            return null;
        }

        return url('/api/playlists/'.$this->id.'/cover').'?v='.substr(md5($this->cover_path), 0, 8);
    }
}
