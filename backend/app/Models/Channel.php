<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = [
        'peer', 'telegram_id', 'title', 'username', 'kind', 'enabled',
        'last_message_id', 'sync_status', 'sync_error', 'sync_progress', 'skipped_count', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /** The peer MadelineProto should resolve: the numeric id once known, otherwise what the admin typed. */
    public function inputPeer(): string|int
    {
        return $this->telegram_id ? (int) $this->telegram_id : $this->peer;
    }
}
