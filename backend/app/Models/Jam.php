<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Jam: members listening to one shared queue, in sync. See JamService for how it changes.
 *
 * @property array<int, array{uid: string, track_id: int, by: int|null}> $queue
 */
class Jam extends Model
{
    protected $fillable = ['code', 'host_id', 'guests_control', 'autofill', 'queue', 'current_uid', 'playing', 'position_ms', 'anchor_ms'];

    protected $attributes = [
        'queue' => '[]', 'version' => 1, 'playing' => false, 'guests_control' => true, 'autofill' => true,
        'position_ms' => 0, 'anchor_ms' => 0,
    ];

    protected function casts(): array
    {
        return [
            'guests_control' => 'boolean',
            'autofill' => 'boolean',
            'queue' => 'array',
            'skip_votes' => 'array',
            'banned' => 'array',
            'playing' => 'boolean',
            'position_ms' => 'integer',
            'anchor_ms' => 'integer',
            'version' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** The server clock in milliseconds; everything in a Jam is timed against it. */
    public static function nowMs(): int
    {
        return (int) now()->getPreciseTimestamp(3);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'jam_members')->withPivot(['listening', 'joined_at', 'last_seen_at']);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public function currentIndex(): int
    {
        foreach ($this->queue as $i => $entry) {
            if ($entry['uid'] === $this->current_uid) {
                return $i;
            }
        }

        return -1;
    }

    /** Where the current song is at the given moment (never before its start). */
    public function positionAt(int $nowMs): int
    {
        return $this->playing ? max(0, $this->position_ms + $nowMs - $this->anchor_ms) : $this->position_ms;
    }

    public function canControl(User $user): bool
    {
        return $user->id === $this->host_id || $this->guests_control;
    }

    public function isBanned(User $user): bool
    {
        return in_array($user->id, $this->banned ?? [], true);
    }
}
