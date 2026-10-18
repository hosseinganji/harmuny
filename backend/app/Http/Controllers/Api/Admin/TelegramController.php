<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Telegram\BotApiException;
use App\Services\Telegram\BotClient;
use App\Services\Telegram\BotUpdates;
use App\Services\Telegram\TelegramManager;
use App\Services\Telegram\TelegramMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TelegramController extends Controller
{
    public function __construct(private TelegramManager $telegram, private BotClient $bot) {}

    public function status(): JsonResponse
    {
        if (TelegramMode::isBot()) {
            return $this->botStatus();
        }

        try {
            $status = $this->telegram->status();
            $account = $status === 'logged_in' ? $this->telegram->self() : null;
            $error = null;
        } catch (\Throwable $e) {
            $status = 'error';
            $account = null;
            $error = $e->getMessage();
        }

        return response()->json([
            'mode' => TelegramMode::USER,
            'status' => $status,
            'api_id' => Setting::get('telegram.api_id'),
            'account' => $account,
            'error' => $error,
        ]);
    }

    public function setMode(Request $request): JsonResponse
    {
        TelegramMode::set($request->validate(['mode' => ['required', 'in:bot,user']])['mode']);

        return $this->status();
    }

    /** Save the bot token after checking it with getMe. */
    public function botToken(Request $request): JsonResponse
    {
        $token = trim($request->validate(['token' => ['required', 'string', 'regex:/^\d+:[\w-]{30,}$/']])['token']);

        try {
            $me = $this->bot->getMe($token);
        } catch (BotApiException $e) {
            throw ValidationException::withMessages(['token' => 'Telegram rejected this token: '.$e->getMessage()]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['token' => 'Could not reach Telegram: '.$e->getMessage()]);
        }

        if ($this->bot->token() !== $token) {
            // A different bot has its own update stream and private chats.
            Setting::put('telegram.update_offset', 0);
            Setting::put('telegram.dump_chat_id', null);
        }
        $this->bot->setToken($token);
        $this->bot->ensurePolling();
        Setting::put('telegram.bot', ['id' => $me['id'], 'username' => $me['username'] ?? null, 'name' => $me['first_name'] ?? '']);
        TelegramMode::set(TelegramMode::BOT);

        return $this->status();
    }

    /** Look for the admin's /start in the bot's updates. */
    public function botCheckStart(BotUpdates $updates): JsonResponse
    {
        try {
            $this->bot->ensurePolling();
            $updates->poll();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['start' => $e->getMessage()]);
        }

        return $this->status();
    }

    public function botDisconnect(): JsonResponse
    {
        Setting::putSecret('telegram.bot_token', null);
        Setting::put('telegram.bot', null);
        Setting::put('telegram.dump_chat_id', null);
        Setting::put('telegram.update_offset', 0);

        return $this->status();
    }

    private function botStatus(): JsonResponse
    {
        $bot = Setting::get('telegram.bot');
        $hasToken = $this->bot->hasToken();
        $dumpChat = Setting::get('telegram.dump_chat_id');

        return response()->json([
            'mode' => TelegramMode::BOT,
            'status' => ! $hasToken ? 'bot_token' : (! $dumpChat ? 'bot_start' : 'logged_in'),
            'bot' => $hasToken ? $bot : null,
            'account' => $dumpChat ? ['name' => Setting::get('telegram.dump_chat_name') ?: ($bot['name'] ?? ''), 'username' => $bot['username'] ?? null, 'phone' => null] : null,
            'max_file_mb' => (int) (config('music.bot.max_file_bytes') / 1048576),
            'api_id' => null,
            'error' => null,
        ]);
    }

    public function credentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_id' => ['required', 'integer', 'min:1'],
            'api_hash' => ['required', 'string', 'size:32'],
        ]);

        // New credentials need a fresh session.
        $this->telegram->logout();
        $this->telegram->setCredentials($data['api_id'], $data['api_hash']);

        return $this->status();
    }

    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{7,20}$/']]);

        return $this->attempt(fn () => $this->telegram->sendCode(str_replace(' ', '', $data['phone'])), 'phone');
    }

    public function submitCode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return $this->attempt(fn () => $this->telegram->submitCode(trim($data['code'])), 'code');
    }

    public function submitPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        return $this->attempt(fn () => $this->telegram->submitPassword($data['password']), 'password');
    }

    public function logout(): JsonResponse
    {
        $this->telegram->logout();

        return $this->status();
    }

    public function dialogs(): JsonResponse
    {
        if (TelegramMode::isBot()) {
            // A bot cannot list its chats; it remembers the ones it was added to (see BotUpdates).
            try {
                app(BotUpdates::class)->poll();
            } catch (\Throwable) {
                // Show what is already known.
            }
            $chats = collect(Setting::get(BotUpdates::CHATS_KEY, []))
                ->map(fn (array $c) => ['id' => $c['id'], 'title' => $c['title'], 'username' => $c['username'], 'broadcast' => $c['type'] === 'channel'])
                ->sortBy('title')
                ->values();

            return response()->json(['data' => $chats]);
        }

        return response()->json(['data' => $this->telegram->listChannels()]);
    }

    private function attempt(callable $action, string $field): JsonResponse
    {
        try {
            $action();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        return $this->status();
    }
}
