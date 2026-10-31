<?php

namespace App\Console\Commands;

use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge copies of the same song (same artist and title, lengths within five seconds): the copy with the most
 * plays stays, the others are hidden, and their plays, likes, playlist places and requests move to it.
 */
class DedupeTracks extends Command
{
    protected $signature = 'music:dedupe {--dry-run : Only list what would be merged}';

    protected $description = 'Merge duplicate songs, keeping plays, likes and playlist entries';

    public function handle(): int
    {
        $keys = Track::query()->selectRaw('match_key')->whereNotNull('match_key')->groupBy('match_key')->havingRaw('COUNT(*) > 1')->pluck('match_key');
        $merged = 0;
        foreach ($keys as $key) {
            $tracks = Track::query()->where('match_key', $key)->get()
                ->sortBy(fn (Track $t) => [-$t->play_count, $t->has_cover ? 0 : 1, $t->isCached() ? 0 : 1, $t->id])->values();
            $keep = $tracks->shift();
            foreach ($tracks as $copy) {
                if ($keep->duration && $copy->duration && abs($keep->duration - $copy->duration) > 5) {
                    continue; // a different version
                }
                $this->line(($this->option('dry-run') ? '[dry] ' : '')."#{$copy->id} → #{$keep->id}  {$keep->artist} – {$keep->title}");
                if (! $this->option('dry-run')) {
                    $this->merge($copy, $keep);
                }
                $merged++;
            }
        }
        $this->info(($this->option('dry-run') ? 'Would merge ' : 'Merged ').$merged.' duplicates.');

        return self::SUCCESS;
    }

    private function merge(Track $copy, Track $keep): void
    {
        DB::transaction(function () use ($copy, $keep) {
            DB::table('plays')->where('track_id', $copy->id)->update(['track_id' => $keep->id]);
            foreach (DB::table('track_likes')->where('track_id', $copy->id)->get() as $like) {
                DB::table('track_likes')->insertOrIgnore(['user_id' => $like->user_id, 'track_id' => $keep->id, 'created_at' => $like->created_at ?? now()]);
            }
            DB::table('track_likes')->where('track_id', $copy->id)->delete();
            DB::table('playlist_track')->where('track_id', $copy->id)->update(['track_id' => $keep->id]);
            DB::table('song_requests')->where('track_id', $copy->id)->update(['track_id' => $keep->id]);
            $keep->forceFill(['play_count' => $keep->play_count + $copy->play_count])->saveQuietly();
            $copy->delete();
        });
    }
}
