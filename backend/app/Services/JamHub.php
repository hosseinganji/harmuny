<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Live updates for Jams over Mercure, the hub built into FrankenPHP (docker/Caddyfile): the server publishes
 * with mercure_publish(), devices listen with an EventSource and a token for their Jam's topic only.
 *
 * Without the hub (php artisan serve, the queue workers) nothing is published and devices fall back to polling.
 */
class JamHub
{
    public function enabled(): bool
    {
        return function_exists('mercure_publish') && $this->key() !== '';
    }

    public function publish(string $topic, array $message): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            mercure_publish($topic, json_encode($message, JSON_UNESCAPED_UNICODE), true);
        } catch (\Throwable $e) {
            // Devices still catch up through their regular ping.
            Log::warning('Jam update not published', ['topic' => $topic, 'error' => $e->getMessage()]);
        }
    }

    /** Where and how a member's device subscribes to a topic (null when there is no hub). */
    public function subscription(string $topic): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return ['url' => url('/.well-known/mercure'), 'topic' => $topic, 'token' => $this->token($topic)];
    }

    /** A JWT (HS256) that lets its holder receive private updates on one topic, for half a day. */
    private function token(string $topic): string
    {
        $encode = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $encode(json_encode(['mercure' => ['subscribe' => [$topic]], 'exp' => time() + 12 * 3600]));

        return "$header.$payload.".$encode(hash_hmac('sha256', "$header.$payload", $this->key(), true));
    }

    private function key(): string
    {
        return (string) config('services.mercure.subscriber_key');
    }
}
