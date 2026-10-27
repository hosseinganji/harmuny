<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendJamInviteJob;
use App\Models\Jam;
use App\Models\User;
use App\Services\JamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Jams: listening together. The rules live in JamService; every answer carries the Jam as devices see it. */
class JamController extends Controller
{
    public function __construct(private JamService $jams) {}

    /** The server clock, for devices to line theirs up with: no sign-in and no database, so it answers fast. */
    public function clock(): JsonResponse
    {
        return response()->json(['now' => Jam::nowMs()]);
    }

    /** The Jam this member is in, if any (an app reopening picks it up again). */
    public function current(Request $request): JsonResponse
    {
        $jam = $this->jams->current($request->user());

        return response()->json($jam ? $this->payload($this->jams->tidy($jam), $request) : ['data' => null]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'track_ids' => ['array', 'max:500'],
            'track_ids.*' => ['integer'],
            'position_ms' => ['integer', 'min:0'],
            'playing' => ['boolean'],
        ]);
        $jam = $this->jams->start($request->user(), $data['track_ids'] ?? [], $data['position_ms'] ?? 0, $data['playing'] ?? false);

        return response()->json($this->payload($jam, $request), 201);
    }

    /** Anyone signed in can look at a Jam before joining it (the invite page). */
    public function show(Request $request, Jam $jam): JsonResponse
    {
        return response()->json($this->payload($jam->isActive() ? $this->jams->tidy($jam) : $jam, $request));
    }

    public function join(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate(['listening' => ['boolean']]);

        return response()->json($this->payload($this->jams->join($jam, $request->user(), $data['listening'] ?? true), $request));
    }

    public function leave(Request $request, Jam $jam): JsonResponse
    {
        $this->jams->leave($jam, $request->user());

        return response()->json(['ok' => true]);
    }

    public function ping(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate(['listening' => ['nullable', 'boolean']]);

        return response()->json($this->payload($this->jams->ping($jam, $request->user(), $data['listening'] ?? null), $request));
    }

    public function playback(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['play', 'pause', 'seek', 'next', 'prev', 'jump', 'advance', 'vote', 'shuffle', 'clear'])],
            'position_ms' => ['nullable', 'integer', 'min:0'],
            'from' => ['nullable', 'string', 'max:16'],
            'uid' => ['nullable', 'string', 'max:16'],
        ]);

        return response()->json($this->payload($this->jams->playback($jam, $request->user(), $data['action'], $data), $request));
    }

    public function add(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate([
            'track_ids' => ['required', 'array', 'min:1', 'max:200'],
            'track_ids.*' => ['integer'],
            'mode' => [Rule::in(['end', 'next', 'play'])],
        ]);

        return response()->json($this->payload($this->jams->add($jam, $request->user(), $data['track_ids'], $data['mode'] ?? 'end'), $request));
    }

    public function reorder(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate(['uids' => ['present', 'array', 'max:600'], 'uids.*' => ['string', 'max:16']]);

        return response()->json($this->payload($this->jams->reorder($jam, $request->user(), $data['uids']), $request));
    }

    public function remove(Request $request, Jam $jam, string $uid): JsonResponse
    {
        return response()->json($this->payload($this->jams->remove($jam, $request->user(), $uid), $request));
    }

    public function react(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:16']]);
        $this->jams->react($jam, $request->user(), $data['emoji']);

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate(['guests_control' => ['boolean'], 'autofill' => ['boolean']]);

        return response()->json($this->payload($this->jams->configure($jam, $request->user(), $data), $request));
    }

    /** Ask members to join, with a notification on their devices. */
    public function invite(Request $request, Jam $jam): JsonResponse
    {
        $data = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:50'], 'user_ids.*' => ['integer']]);
        $this->jams->ensureMember($jam, $request->user());
        SendJamInviteJob::dispatch($jam->id, $request->user()->id, $data['user_ids']);

        return response()->json(['ok' => true]);
    }

    public function kick(Request $request, Jam $jam, User $user): JsonResponse
    {
        return response()->json($this->payload($this->jams->kick($jam, $request->user(), $user), $request));
    }

    public function end(Request $request, Jam $jam): JsonResponse
    {
        return response()->json($this->payload($this->jams->end($jam, $request->user()), $request));
    }

    /** The Jam as devices see it, whether the caller is in it, and (members only) how to get live updates. */
    private function payload(Jam $jam, Request $request): array
    {
        $member = $jam->members()->whereKey($request->user()->id)->exists();

        return [
            'data' => $this->jams->snapshot($jam),
            'member' => $member,
            'hub' => $member && $jam->isActive() ? $this->jams->subscription($jam) : null,
        ];
    }
}
