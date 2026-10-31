<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Discovery\TelegramDiscovery;
use App\Services\Telegram\TelegramManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/** The daily discovery: the Telegram account it reads with, the channels, and the last run. */
class DiscoveryController extends Controller
{
    public function status(TelegramManager $telegram, TelegramDiscovery $discovery): JsonResponse
    {
        try {
            $status = $telegram->status();
            $account = $status === 'logged_in' ? $telegram->self() : null;
            $error = null;
        } catch (\Throwable $e) {
            [$status, $account, $error] = ['error', null, $e->getMessage()];
        }

        return response()->json([
            'account_status' => $status,
            'account' => $account,
            'error' => $error,
            'channels' => TelegramDiscovery::channels(),
            'days' => config('music.discovery.days'),
            'max_per_run' => config('music.discovery.max_per_run'),
            'artists' => array_values($discovery->artists()),
            'last_run' => Setting::get(TelegramDiscovery::LAST_RUN),
        ]);
    }

    public function channels(Request $request, TelegramManager $telegram, TelegramDiscovery $discovery): JsonResponse
    {
        $data = $request->validate(['channels' => ['present', 'array', 'max:30'], 'channels.*' => ['string', 'max:100']]);
        Setting::put('discovery.channels', array_values(array_unique(array_filter(array_map(
            fn ($c) => ltrim(preg_replace('#^(https?://)?t\.me/#', '', trim($c)), '@'),
            $data['channels'],
        )))));

        return $this->status($telegram, $discovery);
    }

    /** Run now, in the background (it downloads songs). */
    public function run(): JsonResponse
    {
        Artisan::queue('music:discover');

        return response()->json(['ok' => true], 202);
    }
}
