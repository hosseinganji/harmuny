<?php

namespace App\Services\Discovery;

use App\Models\Play;
use App\Models\Setting;
use App\Models\Track;
use App\Services\Telegram\MessageParser;
use App\Services\Telegram\TelegramManager;
use App\Services\TrackImporter;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The daily discovery: reads public music channels with the connected Telegram account and brings in new
 * songs by the artists members follow or play most. Only songs posted in the last week (so the server never
 * fills up with a channel's whole history), at most so many per run, never one the library already has.
 */
class TelegramDiscovery
{
    public const LAST_RUN = 'discovery.last_run';

    public function __construct(private TelegramManager $telegram, private TrackImporter $importer) {}

    /** @return list<string> channel usernames */
    public static function channels(): array
    {
        return array_values(array_filter(array_map(
            fn ($c) => ltrim(preg_replace('#^(https?://)?t\.me/#', '', trim((string) $c)), '@'),
            Setting::get('discovery.channels') ?? config('music.discovery.channels'),
        )));
    }

    /**
     * Artists to look for: everyone's follows, then the most played (half a year), then the biggest in the library.
     *
     * @return array<string, string> normalized name => name
     */
    public function artists(): array
    {
        $limit = config('music.discovery.artists');
        $names = DB::table('artist_follows')->distinct()->pluck('artist')
            ->merge(Play::query()->join('tracks', 'tracks.id', '=', 'plays.track_id')
                ->where('plays.played_at', '>=', now()->subDays(180))->whereNotNull('tracks.artist')
                ->groupBy('tracks.artist')->orderByRaw('COUNT(*) DESC')->limit($limit)->pluck('tracks.artist'))
            ->merge(Track::query()->whereNotNull('artist')->groupBy('artist')->orderByRaw('COUNT(*) DESC')->limit($limit)->pluck('artist'));

        $artists = [];
        foreach ($names as $name) {
            // "Shayea & Arta" counts for both.
            foreach (preg_split('/\s*(?:&|،|,|\bft\.?|\bfeat\.?|\bx\b|\band\b|\s-\s)\s*/iu', (string) $name) as $part) {
                $key = TextNormalizer::normalize($part);
                if (mb_strlen($key) >= 3 && count($artists) < $limit * 2) {
                    $artists[$key] ??= trim($part);
                }
            }
        }

        return $artists;
    }

    /**
     * @param  callable(string): void|null  $log
     * @return array{status: string, checked: int, matched: int, added: list<array{id: int, title: string, artist: ?string, channel: string}>, duplicates: int, failed: int, at: string}
     */
    public function run(?callable $log = null): array
    {
        $log ??= fn () => null;
        $result = ['status' => 'ok', 'checked' => 0, 'matched' => 0, 'added' => [], 'duplicates' => 0, 'failed' => 0, 'at' => now()->toIso8601String()];
        if (! $this->telegram->isLoggedIn()) {
            $log('No Telegram account is connected (Admin → Discovery).');
            Setting::put(self::LAST_RUN, ['status' => 'no_account'] + $result);

            return ['status' => 'no_account'] + $result;
        }

        $artists = $this->artists();
        $log('Looking for '.count($artists).' artists in '.implode(', ', self::channels()));
        $since = now()->subDays(config('music.discovery.days'))->getTimestamp();
        $max = config('music.discovery.max_per_run');
        $maxBytes = config('music.discovery.max_file_mb') * 1024 * 1024;

        foreach (self::channels() as $channel) {
            $offset = 0;
            for ($page = 0; $page < 10 && count($result['added']) < $max; $page++) {
                try {
                    $messages = $this->telegram->searchMusic('@'.$channel, $offset, 0, 100)['messages'];
                } catch (\Throwable $e) {
                    $log("@$channel: ".$e->getMessage());
                    $result['failed']++;
                    break;
                }
                if (! $messages) {
                    break;
                }
                foreach ($messages as $message) {
                    $offset = (int) $message['id'];
                    if (($message['date'] ?? 0) < $since) {
                        break 2; // newest first: the rest is older than a week
                    }
                    $result['checked']++;
                    $data = MessageParser::parse($message);
                    if (! $this->byWantedArtist($data['artist'], $artists)) {
                        continue;
                    }
                    $result['matched']++;
                    $externalId = "tg:$channel:{$data['message_id']}";
                    if (Track::withTrashed()->where('external_id', $externalId)->exists()
                        || Track::findDuplicate($data['artist'], $data['title'], $data['duration'])) {
                        $result['duplicates']++;
                        continue;
                    }
                    if ($data['file_size'] > $maxBytes || ($data['duration'] && ($data['duration'] < 60 || $data['duration'] > 900))) {
                        continue; // a whole album or show, or a short clip
                    }
                    if ($added = $this->bring($channel, $message, $data, $externalId, $log)) {
                        $result['added'][] = $added;
                    } else {
                        $result['failed']++;
                    }
                    if (count($result['added']) >= $max) {
                        break 2;
                    }
                }
            }
        }

        $log(sprintf('Checked %d songs, %d by wanted artists, added %d, %d already here.', $result['checked'], $result['matched'], count($result['added']), $result['duplicates']));
        Setting::put(self::LAST_RUN, $result);

        return $result;
    }

    /** Whether a song's artist field names one of the artists we look for. */
    private function byWantedArtist(?string $artist, array $artists): bool
    {
        $field = ' '.TextNormalizer::normalize($artist).' ';
        if (trim($field) === '') {
            return false;
        }
        foreach ($artists as $key => $_) {
            if (str_contains($field, ' '.$key.' ')) {
                return true;
            }
        }

        return false;
    }

    private function bring(string $channel, array $message, array $data, string $externalId, callable $log): ?array
    {
        $temp = storage_path('app/private/discovery-tmp/'.str_replace(':', '-', $externalId).'.part');
        try {
            $this->telegram->downloadToFile($message, $temp);
            $cover = $data['has_cover'] ? $this->telegram->downloadThumb($message) : null;
            ['track' => $track, 'duplicate' => $duplicate] = $this->importer->import($temp, Track::SOURCE_DISCOVERY, [
                'title' => $data['title'],
                'artist' => $data['artist'],
                'duration' => $data['duration'],
                'file_name' => $data['file_name'] ?? ($data['title'].'.mp3'),
                'posted_at' => $data['posted_at'],
                'source_url' => "https://t.me/$channel/{$data['message_id']}",
                'external_id' => $externalId,
                'cover' => $cover,
            ]);
            if ($duplicate) {
                return null;
            }
            $log("+ {$track->artist} – {$track->title} (@$channel)");

            return ['id' => $track->id, 'title' => $track->title, 'artist' => $track->artist, 'channel' => $channel];
        } catch (\Throwable $e) {
            @unlink($temp);
            Log::warning('Discovery download failed', ['channel' => $channel, 'message' => $data['message_id'], 'error' => $e->getMessage()]);
            $log("! @$channel/{$data['message_id']}: ".$e->getMessage());

            return null;
        }
    }
}
