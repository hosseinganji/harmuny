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
            ->withPivot(['id', 'position', 'created_at'])
            ->orderByPivot('position');
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->user_id === $user->id || $this->is_public;
    }

    public function coverUrl(): ?string
    {
        if (! $this->cover_path) {
            return null;
        }

        return url('/api/playlists/'.$this->id.'/cover').'?v='.substr(md5($this->cover_path), 0, 8);
    }
}
