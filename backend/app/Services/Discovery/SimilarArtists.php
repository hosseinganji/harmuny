<?php

namespace App\Services\Discovery;

use App\Services\RadioService;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Artists like the ones members listen to: Deezer's "related artists" (it knows Persian pop and rap well),
 * plus artists the same members here play together. Deezer answers are kept for a week.
 */
class SimilarArtists
{
    public function __construct(private RadioService $radio) {}

    /** @return list<string> */
    public function to(string $artist, int $limit = 6): array
    {
        $names = array_merge($this->fromDeezer($artist, $limit), $this->radio->relatedArtists($artist, 3)->keys()->all());

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function fromDeezer(string $artist, int $limit): array
    {
        return Cache::remember('similar-artists:'.md5(mb_strtolower($artist)), now()->addWeek(), function () use ($artist, $limit) {
            try {
                $found = Http::timeout(10)->get('https://api.deezer.com/search/artist', ['q' => $artist, 'limit' => 3])->json('data') ?? [];
                // Only the artist itself, not someone with a similar name.
                $match = collect($found)->first(fn ($a) => TextNormalizer::normalize($a['name'] ?? '') === TextNormalizer::normalize($artist));
                if (! $match) {
                    return [];
                }
                $related = Http::timeout(10)->get("https://api.deezer.com/artist/{$match['id']}/related", ['limit' => $limit])->json('data') ?? [];

                return array_values(array_filter(array_map(fn ($a) => $a['name'] ?? null, $related)));
            } catch (\Throwable) {
                return []; // Deezer unreachable: try again next week
            }
        });
    }
}
