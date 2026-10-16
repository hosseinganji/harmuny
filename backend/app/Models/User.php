<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'bio',
        'role',
        'status',
        'preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return url('/api/avatars/'.$this->id).'?v='.substr(md5($this->avatar_path), 0, 8);
    }

    public function deleteAvatar(): void
    {
        if ($this->avatar_path) {
            Storage::delete($this->avatar_path);
            $this->avatar_path = null;
        }
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function likedTracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'track_likes')->withPivot('created_at');
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }
}
