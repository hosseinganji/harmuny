<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Stream\MTProtoTransport\ObfuscatedStream;
use danog\MadelineProto\Stream\Proxy\HttpProxy;
use danog\MadelineProto\Stream\Proxy\SocksProxy;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Thin wrapper around a single MadelineProto user session.
 *
 * MadelineProto keeps the session in a background IPC process, so creating
 * the API object in every request is cheap after the first start.
 */
class TelegramManager
{
    private ?API $api = null;

    public function sessionPath(): string
    {
        return storage_path('app/'.config('music.telegram_session'));
    }

    public function hasCredentials(): bool
    {
        return $this->apiId() && $this->apiHash();
    }

    /** The keys entered in the admin panel, else the ones in .env (shared with the local Bot API server). */
    private function apiId(): ?int
    {
        $id = Setting::get('telegram.api_id') ?: config('music.telegram_api_id');

        return $id ? (int) $id : null;
    }

    private function apiHash(): ?string
    {
        return Setting::getSecret('telegram.api_hash') ?: config('music.telegram_api_hash');
    }

    public function setCredentials(int $apiId, string $apiHash): void
    {
        Setting::put('telegram.api_id', $apiId);
        Setting::putSecret('telegram.api_hash', $apiHash);
    }

    public function api(): API
    {
        if ($this->api) {
            return $this->api;
        }
        if (! $this->hasCredentials()) {
            throw new RuntimeException('Telegram API credentials are not configured.');
        }

        File::ensureDirectoryExists(dirname($this->sessionPath()));

        $settings = new Settings;
        $settings->setAppInfo(
            (new AppInfo)
                ->setApiId($this->apiId())
                ->setApiHash($this->apiHash())
                ->setDeviceModel('SmartMusicPlayer')
                ->setAppVersion('1.0')
        );
        $settings->getLogger()
            ->setType(Logger::FILE_LOGGER)
            ->setExtra(storage_path('logs/madeline.log'))
            ->setLevel(Logger::LEVEL_ERROR);
        if ($mtproxy = $this->mtproxy()) {
            $settings->getConnection()->setProxies([ObfuscatedStream::class => [$mtproxy]]);
        } elseif ($proxy = $this->proxy()) {
            $settings->getConnection()->setProxies($proxy);
        }

        return $this->api = new API($this->sessionPath(), $settings);
    }

    /** The configured MTProxy link as MadelineProto proxy settings, or null when none is set. */
    private function mtproxy(): ?array
    {
        $link = config('music.telegram_mtproxy');
        if (! $link) {
            return null;
        }
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        if (empty($query['server']) || empty($query['port']) || empty($query['secret'])) {
            throw new RuntimeException('TELEGRAM_MTPROXY must look like https://t.me/proxy?server=...&port=...&secret=...');
        }

        return ['address' => $query['server'], 'port' => (int) $query['port'], 'secret' => $query['secret']];
    }

    /** TELEGRAM_PROXY (socks5://, socks5h:// or http://) as MadelineProto proxy settings, or null when none is set. */
    private function proxy(): ?array
    {
        $url = config('music.telegram_proxy');
        if (! $url) {
            return null;
        }
        $parts = parse_url($url);
        $class = str_starts_with($parts['scheme'] ?? '', 'socks') ? SocksProxy::class : HttpProxy::class;
        $extra = ['address' => $parts['host'] ?? '127.0.0.1', 'port' => (int) ($parts['port'] ?? 1080)];
        if (isset($parts['user'], $parts['pass'])) {
            $extra += ['username' => urldecode($parts['user']), 'password' => urldecode($parts['pass'])];
        }

        return [$class => [$extra]];
    }

    /** One of: unconfigured, logged_out, waiting_code, waiting_password, waiting_signup, logged_in. */
    public function status(): string
    {
        if (! $this->hasCredentials()) {
            return 'unconfigured';
        }
        if (! is_dir($this->sessionPath())) {
            return 'logged_out';
        }

        return match ($this->api()->getAuthorization()) {
            API::LOGGED_IN => 'logged_in',
            API::WAITING_CODE => 'waiting_code',
            API::WAITING_PASSWORD => 'waiting_password',
            API::WAITING_SIGNUP => 'waiting_signup',
            default => 'logged_out',
        };
    }

    public function isLoggedIn(): bool
    {
        try {
            return $this->status() === 'logged_in';
        } catch (\Throwable) {
            return false;
        }
    }

    public function self(): ?array
    {
        $self = $this->api()->getSelf();

        return $self ? [
            'id' => $self['id'],
            'name' => trim(($self['first_name'] ?? '').' '.($self['last_name'] ?? '')),
            'username' => $self['username'] ?? null,
            'phone' => $self['phone'] ?? null,
        ] : null;
    }

    public function sendCode(string $phone): void
    {
        $this->api()->phoneLogin($phone);
    }

    /** Returns the next status after submitting the code. */
    public function submitCode(string $code): string
    {
        $result = $this->api()->completePhoneLogin($code);

        return match ($result['_'] ?? null) {
            'account.password' => 'waiting_password',
            'account.needSignup' => 'waiting_signup',
            default => 'logged_in',
        };
    }

    public function submitPassword(string $password): void
    {
        $this->api()->complete2faLogin($password);
    }

    /** Log out and wipe the session so a fresh login (possibly with new credentials) can start. */
    public function logout(): void
    {
        try {
            if ($this->isLoggedIn()) {
                $this->api()->logout();
            }
        } catch (\Throwable) {
            // The session is wiped below anyway.
        }
        $this->api = null;
        File::deleteDirectory($this->sessionPath());
    }

    /** @return array{id:int,title:string,username:?string} */
    public function resolveChannel(string|int $peer): array
    {
        $info = $this->api()->getInfo($peer);
        if (! in_array($info['type'] ?? null, ['channel', 'supergroup'], true)) {
            throw new RuntimeException('The peer is not a channel.');
        }

        return [
            'id' => (int) $info['bot_api_id'],
            'title' => $info['Chat']['title'] ?? (string) $peer,
            'username' => $info['Chat']['username'] ?? null,
        ];
    }

    /** Channels the logged-in account is a member of, for picking private channels. */
    public function listChannels(): array
    {
        $dialogs = $this->api()->messages->getDialogs(
            offset_date: 0, offset_id: 0, offset_peer: ['_' => 'inputPeerEmpty'], limit: 200, hash: 0
        );

        return collect($dialogs['chats'] ?? [])
            ->filter(fn ($chat) => ($chat['_'] ?? null) === 'channel' && empty($chat['left']))
            ->map(fn ($chat) => [
                'id' => (int) ('-100'.$chat['id']),
                'title' => $chat['title'] ?? '',
                'username' => $chat['username'] ?? null,
                'broadcast' => (bool) ($chat['broadcast'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * One page of music messages, newest first.
     *
     * @return array{messages: list<array>, count: int}
     */
    public function searchMusic(string|int $peer, int $offsetId = 0, int $minId = 0, int $limit = 100): array
    {
        $result = $this->api()->messages->search(
            peer: $peer,
            q: '',
            filter: ['_' => 'inputMessagesFilterMusic'],
            min_date: 0,
            max_date: 0,
            offset_id: $offsetId,
            add_offset: 0,
            limit: $limit,
            max_id: 0,
            min_id: $minId,
            hash: 0,
        );

        return [
            'messages' => array_values(array_filter(
                $result['messages'] ?? [],
                fn ($m) => ($m['_'] ?? null) === 'message' && isset($m['media']['document'])
            )),
            'count' => (int) ($result['count'] ?? count($result['messages'] ?? [])),
        ];
    }

    public function getMessage(string|int $peer, int $messageId): ?array
    {
        $result = $this->api()->channels->getMessages(channel: $peer, id: [$messageId]);
        $message = $result['messages'][0] ?? null;

        return ($message && ($message['_'] ?? null) === 'message' && isset($message['media']['document']))
            ? $message
            : null;
    }

    /** Download bytes [$start, $end] (inclusive) of a message's document and pass each chunk to $write. */
    public function streamRange(array $message, int $start, int $end, callable $write): void
    {
        $this->api()->downloadToCallable(
            $message['media'],
            function (string $chunk) use ($write): int {
                $write($chunk);

                return strlen($chunk);
            },
            null,
            false,
            $start,
            $end + 1,
        );
    }

    public function downloadToFile(array $message, string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        $this->api()->downloadToFile($message['media'], $path);
    }

    /** Download the largest embedded thumbnail of a message's document, or null if it has none. */
    public function downloadThumb(array $message): ?string
    {
        $document = $message['media']['document'];
        $thumb = collect($document['thumbs'] ?? [])
            ->filter(fn ($t) => in_array($t['_'] ?? '', ['photoSize', 'photoSizeProgressive'], true))
            ->sortByDesc(fn ($t) => ($t['w'] ?? 0) * ($t['h'] ?? 0))
            ->first();
        if (! $thumb) {
            return null;
        }

        $size = $thumb['size'] ?? (isset($thumb['sizes']) ? end($thumb['sizes']) : 0);
        $data = '';
        $this->api()->downloadToCallable(
            [
                'InputFileLocation' => [
                    '_' => 'inputDocumentFileLocation',
                    'id' => $document['id'],
                    'access_hash' => $document['access_hash'],
                    'file_reference' => $document['file_reference'],
                    'thumb_size' => $thumb['type'],
                    'dc_id' => $document['dc_id'],
                ],
                'size' => $size,
                'mime' => 'image/jpeg',
                'ext' => '.jpg',
                'name' => 'thumb',
            ],
            function (string $chunk) use (&$data): int {
                $data .= $chunk;

                return strlen($chunk);
            },
            null,
            false,
        );

        return $data !== '' ? $data : null;
    }
}
