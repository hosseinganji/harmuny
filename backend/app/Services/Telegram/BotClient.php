<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/** Minimal Telegram Bot API client. */
class BotClient
{
    private const MAX_RATE_LIMIT_RETRIES = 6;

    public function token(): ?string
    {
        return Setting::getSecret('telegram.bot_token');
    }

    public function setToken(string $token): void
    {
        Setting::putSecret('telegram.bot_token', $token);
    }

    public function hasToken(): bool
    {
        return (bool) $this->token();
    }

    private function http(int $timeout = 30): PendingRequest
    {
        $request = Http::baseUrl(rtrim(config('music.bot.api_url'), '/'))
            ->timeout($timeout)
            ->connectTimeout(15)
            ->acceptJson();

        if ($proxy = config('music.telegram_proxy')) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    /**
     * Call a Bot API method and return its `result`.
     * Rate limits (429) are waited out and retried.
     */
    public function call(string $method, array $params = [], ?string $token = null, int $timeout = 30): mixed
    {
        $token ??= $this->token();
        if (! $token) {
            throw new BotApiException('The bot token is not configured.');
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->http($timeout)->post("/bot{$token}/{$method}", $params);
            } catch (ConnectionException $e) {
                throw self::unreachable($e, $token);
            }
            $body = $response->json() ?? [];

            if (($body['ok'] ?? false) === true) {
                return $body['result'];
            }

            $code = (int) ($body['error_code'] ?? $response->status());
            $retryAfter = (int) ($body['parameters']['retry_after'] ?? 0);
            if ($code === 429 && $attempt < self::MAX_RATE_LIMIT_RETRIES) {
                sleep(max(1, $retryAfter));

                continue;
            }

            throw new BotApiException($body['description'] ?? ('HTTP '.$response->status()), $code);
        }
    }

    /** A network failure, with the bot token removed from the message (it is part of every URL). */
    private static function unreachable(ConnectionException $e, ?string $token): BotApiException
    {
        $detail = $token ? str_replace($token, '<token>', $e->getMessage()) : $e->getMessage();

        return new BotApiException('Cannot reach Telegram. Check the VPN or TELEGRAM_PROXY. ('.$detail.')');
    }

    public function getMe(?string $token = null): array
    {
        return $this->call('getMe', [], $token);
    }

    /**
     * The app reads updates by polling, which Telegram refuses while a webhook is set
     * (e.g. one left over from another project). Remove it, keeping pending updates.
     *
     * @return string|null the webhook URL that was removed
     */
    public function ensurePolling(?string $token = null): ?string
    {
        $url = $this->call('getWebhookInfo', [], $token)['url'] ?? '';
        if ($url === '') {
            return null;
        }
        $this->call('deleteWebhook', ['drop_pending_updates' => false], $token);

        return $url;
    }

    /** Absolute download URL of a file (valid for at least an hour). Throws for files over 20 MB. */
    public function fileUrl(string $fileId): string
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);

        return rtrim(config('music.bot.api_url'), '/').'/file/bot'.$this->token().'/'.$file['file_path'];
    }

    public function download(string $fileId, string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        try {
            $response = $this->http(600)->sink($path)->get($this->fileUrl($fileId));
        } catch (ConnectionException $e) {
            throw self::unreachable($e, $this->token());
        }
        if (! $response->successful()) {
            File::delete($path);
            throw new BotApiException('Download failed: HTTP '.$response->status(), $response->status());
        }
    }

    public function fileBytes(string $fileId): string
    {
        try {
            $response = $this->http(60)->get($this->fileUrl($fileId));
        } catch (ConnectionException $e) {
            throw self::unreachable($e, $this->token());
        }
        if (! $response->successful()) {
            throw new BotApiException('Download failed: HTTP '.$response->status(), $response->status());
        }

        return $response->body();
    }
}
