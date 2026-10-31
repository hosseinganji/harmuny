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

    /** Posted in a channel the app syncs. */
    public const SOURCE_TELEGRAM = 'telegram';
    /** Uploaded by an admin; the file lives only on this server. */
    public const SOURCE_UPLOAD = 'upload';
    /** Found by the daily discovery in a public channel; the file lives on this server. */
    public const SOURCE_DISCOVERY = 'discovery';

    protected $fillable = [
        'channel_id', 'message_id', 'file_id', 'thumb_file_id', 'title', 'artist', 'album', 'genre', 'year', 'duration',
        'file_name', 'mime_type', 'file_size', 'caption', 'has_cover',
        'metadata_locked', 'posted_at', 'source', 'source_url', 'external_id', 'added_by',
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
            $track->match_key = self::matchKey($track->artist, $track->title);
        });
        // Someone may have asked for this song.
        static::created(fn (Track $track) => \App\Services\SongRequests::fulfil($track));
    }

    /**
     * What makes two files "the same song": artist and title, normalized, without the usual decorations
     * ("Official Audio", "(Remastered)", "[320]", "ft. …", a leading "Artist - "). Durations are compared too
     * (see findDuplicate), so a remix or a live version is kept apart.
     */
    public static function matchKey(?string $artist, ?string $title): string
    {
        $artist = TextNormalizer::normalize($artist);
        $title = (string) $title;
        $title = preg_replace('/[(\[{【].*?[)\]}】]/u', ' ', $title);
        $title = preg_replace('/\b(feat|ft|featuring)\b.*$/iu', ' ', $title);
        $title = TextNormalizer::normalize($title);
        $title = preg_replace('/\b(official|audio|video|music|lyrics?|lyric video|visualizer|hq|hd|320|128|mp3)\b/u', ' ', $title);
        if ($artist !== '' && str_starts_with($title, $artist.' ')) {
            $title = substr($title, strlen($artist) + 1);
        }
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        return mb_substr($artist.'|'.$title, 0, 191);
    }

    /** The same song already in the library (hidden ones too: a song an admin hid stays out). */
    public static function findDuplicate(?string $artist, string $title, int $duration = 0): ?self
    {
        return self::withTrashed()
            ->where('match_key', self::matchKey($artist, $title))
            ->get()
            ->first(fn (self $t) => ! $duration || ! $t->duration || abs($t->duration - $duration) <= 5);
    }

    /** Link to a channel post: t.me/name/123 for public channels, t.me/c/… for private ones. */
    public static function postUrl(?string $peer, ?int $telegramId, int $messageId): ?string
    {
        $peer = ltrim(preg_replace('#^(https?://)?t\.me/#', '', (string) $peer), '@');
        if ($peer !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]{3,}$/', $peer)) {
            return "https://t.me/$peer/$messageId";
        }
        if ($telegramId) {
            return 'https://t.me/c/'.preg_replace('/^-100/', '', (string) $telegramId)."/$messageId";
        }

        return null;
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** Kept on this server for good (no Telegram copy to fetch again), so the cache never prunes it. */
    public function isPinned(): bool
    {
        return $this->source !== self::SOURCE_TELEGRAM;
    }

    /** Approximate bitrate in kbps, from the size and length. */
    public function bitrate(): ?int
    {
        return $this->duration > 0 && $this->file_size > 0 ? (int) round($this->file_size * 8 / $this->duration / 1000) : null;
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
