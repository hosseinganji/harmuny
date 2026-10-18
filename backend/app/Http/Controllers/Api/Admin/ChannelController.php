<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncChannelJob;
use App\Models\Channel;
use App\Services\Telegram\BotChannelSyncer;
use App\Services\Telegram\TelegramManager;
use App\Services\Telegram\TelegramMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChannelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Channel::query()->withCount('tracks')->orderBy('id')->get()]);
    }

    public function store(Request $request, TelegramManager $telegram): JsonResponse
    {
        $data = $request->validate(['peer' => ['required', 'string', 'max:200']]);
        $peer = $this->normalizePeer($data['peer']);

        try {
            if (TelegramMode::isBot()) {
                $syncer = app(BotChannelSyncer::class);
                $info = $syncer->resolve($peer);
                $syncer->assertAccess($info['id'], $info['type']);
            } else {
                $info = $telegram->resolveChannel(is_numeric($peer) ? (int) $peer : $peer);
            }
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['peer' => $e->getMessage()]);
        }

        $channel = Channel::query()->updateOrCreate(['telegram_id' => $info['id']], [
            'peer' => (string) $peer,
            'title' => $info['title'],
            'username' => $info['username'],
            'kind' => $info['type'] ?? (($info['broadcast'] ?? true) ? 'channel' : 'supergroup'),
            'enabled' => true,
        ]);
        SyncChannelJob::dispatch($channel, true);

        return response()->json(['data' => $channel->loadCount('tracks')], 201);
    }

    public function update(Request $request, Channel $channel): JsonResponse
    {
        $channel->update($request->validate(['enabled' => ['required', 'boolean']]));

        return response()->json(['data' => $channel->loadCount('tracks')]);
    }

    public function destroy(Request $request, Channel $channel): JsonResponse
    {
        if ($request->boolean('delete_tracks')) {
            $channel->tracks()->delete();
        }
        $channel->delete();

        return response()->json(['ok' => true]);
    }

    public function sync(Request $request, Channel $channel): JsonResponse
    {
        // A sync already in progress keeps its status; the unique job won't be queued twice.
        if ($channel->sync_status !== 'syncing') {
            $channel->update(['sync_status' => 'queued', 'sync_error' => null]);
        }
        SyncChannelJob::dispatch($channel, $request->boolean('full'));

        return response()->json(['data' => $channel->loadCount('tracks')]);
    }

    /** Accept @name, t.me links or numeric ids. */
    private function normalizePeer(string $peer): string
    {
        $peer = trim($peer);
        if (preg_match('~(?:https?://)?(?:t\.me|telegram\.me)/(?:s/)?([A-Za-z0-9_]{4,})~', $peer, $m)) {
            return '@'.$m[1];
        }

        return $peer;
    }
}
