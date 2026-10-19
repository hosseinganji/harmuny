<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\Track;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Tells members about songs that just arrived. New posts are collected for a minute and sent as one
 * notification, so a burst of uploads doesn't buzz everyone's phone repeatedly.
 */
class NotifyNewTracksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public const PENDING_KEY = 'push.pending_tracks';

    /** Collect a newly posted song and schedule the (single) notification. */
    public static function collect(Track $track): void
    {
        $pending = Cache::get(self::PENDING_KEY, []);
        $pending[] = $track->id;
        Cache::put(self::PENDING_KEY, array_values(array_unique($pending)), now()->addHour());
        self::dispatch()->delay(now()->addMinute());
    }

    public function handle(PushService $push): void
    {
        $ids = Cache::pull(self::PENDING_KEY, []);
        $tracks = Track::query()->whereIn('id', $ids)->orderBy('posted_at')->get();
        if ($tracks->isEmpty()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->with('user')
            ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->get();

        // Each member gets it in the app language they chose.
        foreach ($subscriptions->groupBy(fn ($s) => ($s->user->preferences['language'] ?? 'en') === 'fa' ? 'fa' : 'en') as $lang => $subs) {
            $push->send($subs, self::payload($tracks->all(), $lang));
        }
    }

    /** @param  Track[]  $tracks */
    public static function payload(array $tracks, string $lang): array
    {
        $count = count($tracks);
        $line = fn (Track $t) => trim(($t->artist ? $t->artist.' – ' : '').$t->title);
        $persianDigits = fn (int $n) => strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        $title = $lang === 'fa'
            ? ($count === 1 ? 'آهنگ جدید' : $persianDigits($count).' آهنگ جدید')
            : ($count === 1 ? 'New song' : $count.' new songs');
        $body = implode("\n", array_map($line, array_slice($tracks, 0, 3))).($count > 3 ? "\n…" : '');

        return ['title' => '🎵 '.$title, 'body' => $body, 'url' => '/library/songs', 'tag' => 'new-songs'];
    }
}
