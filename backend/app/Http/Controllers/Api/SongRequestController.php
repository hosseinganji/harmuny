<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\SongRequest;
use App\Models\Track;
use App\Services\SongRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Members ask for songs the library lacks; admins see every request and answer them. */
class SongRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $requests = SongRequest::query()
            ->with(['user', 'track', 'handler'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByRaw("status = 'pending' DESC")
            ->latest()
            ->limit(200)
            ->get();

        return response()->json(['data' => $requests->map(fn (SongRequest $r) => $this->present($r, $request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'artist' => ['nullable', 'string', 'max:250'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $songRequest = SongRequest::query()->create($data + ['user_id' => $request->user()->id]);
        // Already in the library: answer right away.
        [, $title] = explode('|', Track::matchKey(null, $data['title']), 2);
        $existing = $title === '' ? null : Track::query()->where('match_key', 'like', '%|'.addcslashes($title, '%_\\'))->get()
            ->first(fn (Track $t) => SongRequests::answers($t, $songRequest));
        if ($existing) {
            $songRequest->update(['status' => SongRequest::FULFILLED, 'track_id' => $existing->id, 'handled_at' => now()]);
        } else {
            SongRequests::created($songRequest);
        }

        return response()->json(['data' => $this->present($songRequest->load(['user', 'track']), $request)], 201);
    }

    /** Admins: answer with a song from the library, or turn it down. */
    public function update(Request $request, SongRequest $songRequest): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in([SongRequest::FULFILLED, SongRequest::REJECTED, SongRequest::PENDING])],
            'track_id' => ['required_if:status,fulfilled', 'nullable', 'integer', 'exists:tracks,id'],
        ]);
        if ($data['status'] === SongRequest::FULFILLED) {
            SongRequests::markFulfilled($songRequest, Track::query()->findOrFail($data['track_id']), $request->user());
        } else {
            $songRequest->update(['status' => $data['status'], 'track_id' => null, 'handled_by' => $request->user()->id, 'handled_at' => now()]);
        }

        return response()->json(['data' => $this->present($songRequest->refresh()->load(['user', 'track', 'handler']), $request)]);
    }

    public function destroy(Request $request, SongRequest $songRequest): JsonResponse
    {
        abort_unless($request->user()->isAdmin() || $songRequest->user_id === $request->user()->id, 403);
        $songRequest->delete();

        return response()->json(['ok' => true]);
    }

    private function present(SongRequest $r, Request $request): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'artist' => $r->artist,
            'note' => $r->note,
            'status' => $r->status,
            'created_at' => $r->created_at?->toIso8601String(),
            'handled_at' => $r->handled_at?->toIso8601String(),
            'user' => ['id' => $r->user->id, 'name' => $r->user->name, 'avatar_url' => $r->user->avatarUrl()],
            'handler' => $r->handler ? ['id' => $r->handler->id, 'name' => $r->handler->name] : null,
            'track' => $r->track ? (new TrackResource($r->track))->resolve($request) : null,
        ];
    }
}
