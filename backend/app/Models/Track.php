<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Track extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'channel_id', 'message_id', 'file_id', 'thumb_file_id', 'title', 'artist', 'album', 'genre', 'year', 'duration',
        'file_name', 'mime_type', 'file_size', 'caption', 'has_cover',
        'metadata_locked', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'has_cover' => 'boolean',
            'metadata_locked' => 'boolean',
            'posted_at' => 'datetime',
            'cached_at' => 'datetime',
            'cache_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Track $track) {
            $track->search_text = TextNormalizer::normalize(implode(' ', array_filter([
                $track->title, $track->artist, $track->album, $track->genre, $track->file_name, $track->caption,
            ])));
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'track_likes');
    }

    /** Match every word of the query against the normalized search text. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        foreach (TextNormalizer::words($term) as $word) {
            $query->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $query;
    }

    public function isCached(): bool
    {
        return $this->cache_path !== null && is_file(Storage::path($this->cache_path));
    }

    public function cacheAbsolutePath(): ?string
    {
        return $this->cache_path ? Storage::path($this->cache_path) : null;
    }
}
