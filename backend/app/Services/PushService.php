<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * Sends Web Push notifications. The VAPID key pair is created on first use and kept in settings
 * (private key encrypted), so a fresh install needs no setup.
 */
class PushService
{
    /** @return array{publicKey: string, privateKey: string} */
    public function keys(): array
    {
        $public = Setting::get('push.vapid_public');
        $private = Setting::getSecret('push.vapid_private');
        if (! $public || ! $private) {
            ['publicKey' => $public, 'privateKey' => $private] = VAPID::createVapidKeys();
            Setting::put('push.vapid_public', $public);
            Setting::putSecret('push.vapid_private', $private);
        }

        return ['publicKey' => $public, 'privateKey' => $private];
    }

    /**
     * Send one payload to many devices; subscriptions the push service reports as gone are removed.
     *
     * @param  iterable<PushSubscription>  $subscriptions
     */
    public function send(iterable $subscriptions, array $payload): int
    {
        $keys = $this->keys();
        $push = new WebPush(['VAPID' => [
            'subject' => 'mailto:admin@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]], ['TTL' => 6 * 3600, 'urgency' => 'normal']);

        $byEndpoint = [];
        foreach ($subscriptions as $sub) {
            $byEndpoint[$sub->endpoint] = $sub;
            $push->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->public_key,
                'authToken' => $sub->auth_token,
                'contentEncoding' => $sub->content_encoding,
            ]), json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $sent = 0;
        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                $byEndpoint[$report->getEndpoint()]?->delete();
            } else {
                Log::warning('Push failed', ['reason' => $report->getReason()]);
            }
        }

        return $sent;
    }
}
