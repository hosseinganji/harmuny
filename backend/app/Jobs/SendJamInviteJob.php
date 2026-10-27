<?php

namespace App\Jobs;

use App\Models\Jam;
use App\Models\PushSubscription;
use App\Models\Track;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** "Soheil invited you to a Jam": a notification that opens the Jam's invite page. */
class SendJamInviteJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @param  int[]  $userIds */
    public function __construct(public int $jamId, public int $fromId, public array $userIds) {}

    public function handle(PushService $push): void
    {
        $jam = Jam::query()->find($this->jamId);
        $from = User::query()->find($this->fromId);
        if (! $jam?->isActive() || ! $from) {
            return;
        }
        $current = $jam->queue[$jam->currentIndex()] ?? null;
        $track = $current ? Track::query()->find($current['track_id']) : null;

        $subscriptions = PushSubscription::query()
            ->with('user')
            ->whereIn('user_id', $this->userIds)
            ->where('user_id', '!=', $from->id)
            ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->get();

        foreach ($subscriptions->groupBy(fn ($s) => ($s->user->preferences['language'] ?? 'en') === 'fa' ? 'fa' : 'en') as $lang => $subs) {
            $push->send($subs, self::payload($jam, $from, $track, $lang));
        }
    }

    public static function payload(Jam $jam, User $from, ?Track $track, string $lang): array
    {
        $song = $track ? trim(($track->artist ? $track->artist.' – ' : '').$track->title) : null;
        [$title, $body] = $lang === 'fa'
            ? [$from->name.' تو را به پخش گروهی دعوت کرد', $song ? 'الان: '.$song : 'بیا با هم آهنگ گوش بدیم']
            : [$from->name.' invited you to a Jam', $song ? 'Now: '.$song : "Let's listen together"];

        return ['title' => '🎧 '.$title, 'body' => $body, 'url' => '/jam/'.$jam->code, 'tag' => 'jam-'.$jam->code];
    }
}
