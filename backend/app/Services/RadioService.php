<?php

namespace App\Services;

use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Radio and daily mixes, built from what members actually listen to.
 *
 * Two artists are "related" when the same people play both. A candidate song scores for being by the
 * seed artist, by a related artist, in the seed's genre, and for being popular, plus some randomness;
 * the result is ordered so one artist never plays three times in a row.
 */
class RadioService
{
    /** How far back listening habits count. */
    private const HISTORY_DAYS = 180;

    /**
     * Artists played by the same people as $artist, with a 0–1 strength.
     *
     * @return Collection<string, float>
     */
    public function relatedArtists(string $artist, int $limit = 25): Collection
    {
        $since = now()->subDays(self::HISTORY_DAYS);
        $listeners = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->where('tracks.artist', $artist)
            ->where('plays.played_at', '>=', $since)
            ->distinct()
            ->pluck('plays.user_id');
        if ($listeners->isEmpty()) {
            return collect();
        }

        $counts = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereIn('plays.user_id', $listeners)
            ->where('plays.played_at', '>=', $since)
            ->whereNotNull('tracks.artist')
            ->where('tracks.artist', '!=', $artist)
            ->groupBy('tracks.artist')
            ->orderByDesc(DB::raw('COUNT(DISTINCT plays.user_id) * 10 + COUNT(*)'))
            ->limit($limit)
            ->selectRaw('tracks.artist as artist, COUNT(DISTINCT plays.user_id) * 10 + COUNT(*) as weight')
            ->pluck('weight', 'artist');

        $max = max(1, (int) $counts->max());

        return $counts->map(fn ($w) => round($w / $max, 3));
    }

    /**
     * A radio queue from a song or an artist.
     *
     * @param  int[]  $exclude  songs already in the queue
     * @return Collection<int, Track>
     */
    public function radio(?Track $seed, ?string $artist, array $exclude = [], int $limit = 30): Collection
    {
        $artist ??= $seed?->artist;
        $genre = $seed?->genre ?? ($artist ? Track::query()->where('artist', $artist)->whereNotNull('genre')->value('genre') : null);
        $related = $artist ? $this->relatedArtists($artist) : collect();

        $candidates = Track::query()
            ->whereNotIn('id', array_merge($exclude, $seed ? [$seed->id] : []))
            ->where(fn ($q) => $q
                ->when($artist, fn ($q) => $q->orWhere('artist', $artist))
                ->when($related->isNotEmpty(), fn ($q) => $q->orWhereIn('artist', $related->keys()))
                ->when($genre, fn ($q) => $q->orWhere('genre', $genre))
                ->orWhere('play_count', '>', 0))
            ->get();

        // Too little listening data yet: top up with the rest of the library.
        if ($candidates->count() < $limit) {
            $candidates = $candidates->merge(
                Track::query()->whereNotIn('id', array_merge($exclude, $candidates->pluck('id')->all(), $seed ? [$seed->id] : []))
                    ->inRandomOrder()->limit($limit * 2)->get()
            );
        }

        $maxPlays = max(1, (int) $candidates->max('play_count'));
        $scored = $candidates->map(function (Track $t) use ($artist, $related, $genre, $maxPlays) {
            $score = 0.0;
            if ($artist && $t->artist === $artist) {
                $score += 2.5;
            }
            $score += 3.0 * ($related[$t->artist] ?? 0);
            if ($genre && $t->genre === $genre) {
                $score += 1.2;
            }
            $score += 1.0 * log(1 + $t->play_count) / log(1 + $maxPlays);
            $score += mt_rand() / mt_getrandmax() * 1.6; // variety

            return ['track' => $t, 'score' => $score];
        })->sortByDesc('score')->take($limit * 2)->pluck('track');

        // The seed leads, and takes part in the spreading so it cannot start a run of three.
        return $this->spreadArtists($seed ? $scored->prepend($seed) : $scored)->take($limit + ($seed ? 1 : 0))->values();
    }

    /**
     * Today's mix for a member: their favourite artists, artists like them, and a few discoveries.
     * The same all day (seeded by the date), different tomorrow.
     *
     * @return Collection<int, Track>
     */
    public function dailyMix(User $user, int $limit = 30): Collection
    {
        // Every random step has its own seed from member + date, so the mix holds all day.
        $day = crc32($user->id.'|'.now()->toDateString());
        $seed = fn (int $step) => ($day + $step * 7919) % 2147483647;

        $top = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->where('plays.user_id', $user->id)
            ->where('plays.played_at', '>=', now()->subDays(30))
            ->whereNotNull('tracks.artist')
            ->groupBy('tracks.artist')
            ->selectRaw('tracks.artist as artist, COUNT(*) as c')
            ->orderByDesc('c')
            ->orderBy('artist')
            ->limit(6)
            ->pluck('c', 'artist');

        if ($top->isEmpty()) {
            // Nothing played yet: what everyone likes, plus recent additions.
            $popular = Track::query()->where('play_count', '>', 0)->orderByDesc('play_count')->orderBy('id')->limit($limit)->get();
            $recent = Track::query()->latest('posted_at')->orderBy('id')->limit($limit)->get();

            return $this->spreadArtists($this->shuffled($popular->merge($recent)->unique('id'), $seed(1)))->take($limit)->values();
        }

        $playedRecently = Play::query()->where('user_id', $user->id)->where('played_at', '>=', now()->subDays(3))->pluck('track_id')->unique();
        $related = collect();
        foreach ($top->keys()->take(3) as $artist) {
            $related = $related->union($this->relatedArtists($artist, 8));
        }
        $related = $related->except($top->keys()->all());
        $everPlayed = Play::query()->where('user_id', $user->id)->distinct()->pluck('track_id');

        $pick = fn ($query, int $step, float $share) => $this->shuffled($query->orderBy('id')->get(), $seed($step))->take((int) round($limit * $share));
        $favourites = $pick(Track::query()->whereIn('artist', $top->keys())->whereNotIn('id', $playedRecently), 2, 0.5);
        $similar = $pick(Track::query()->whereIn('artist', $related->keys())->whereNotIn('id', $playedRecently), 3, 0.3);
        $discover = $pick(Track::query()->whereNotIn('id', $everPlayed)->orderByDesc('play_count')->limit($limit), 4, 0.2);

        $mix = $favourites->merge($similar)->merge($discover)->unique('id');
        if ($mix->count() < $limit) {
            $rest = Track::query()->whereNotIn('id', $mix->pluck('id'))->orderBy('id')->get();
            $mix = $mix->merge($this->shuffled($rest, $seed(5))->take($limit - $mix->count()));
        }

        return $this->spreadArtists($this->shuffled($mix, $seed(6)))->take($limit)->values();
    }

    /** A repeatable shuffle: the same seed always gives the same order. */
    private function shuffled(Collection $items, int $seed): Collection
    {
        return collect((new Randomizer(new Mt19937($seed)))->shuffleArray($items->values()->all()));
    }

    /** Reorder so the same artist never plays more than twice in a row. */
    private function spreadArtists(Collection $tracks): Collection
    {
        $pending = $tracks->values()->all();
        $out = [];
        while ($pending) {
            $streak = count($out) >= 2 && $out[count($out) - 1]->artist === $out[count($out) - 2]->artist ? $out[count($out) - 1]->artist : null;
            $pick = 0;
            if ($streak !== null) {
                foreach ($pending as $i => $t) {
                    if ($t->artist !== $streak) {
                        $pick = $i;
                        break;
                    }
                }
            }
            $out[] = $pending[$pick];
            array_splice($pending, $pick, 1);
        }

        return collect($out);
    }
}
