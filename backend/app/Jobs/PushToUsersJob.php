<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** A notification for some members, in the language each of them uses. */
class PushToUsersJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * @param  int[]  $userIds
     * @param  array{fa: array, en: array}  $payloads
     */
    public function __construct(public array $userIds, public array $payloads) {}

    public function handle(PushService $push): void
    {
        $subscriptions = PushSubscription::query()
            ->with('user')
            ->whereIn('user_id', $this->userIds)
            ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->get();

        foreach ($subscriptions->groupBy(fn ($s) => ($s->user->preferences['language'] ?? 'en') === 'fa' ? 'fa' : 'en') as $lang => $subs) {
            $push->send($subs, $this->payloads[$lang]);
        }
    }
}
