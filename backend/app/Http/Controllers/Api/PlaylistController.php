<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlaylistController extends Controller
{
    /** Attach up to four track ids per playlist for mosaic covers. */
    public static function attachPreviews(Collection $playlists): void
    {
        if ($playlists->isEmpty()) {
            return;
        }
        $rows = DB::table('playlist_track')
            ->join('tracks', 'tracks.id', '=', 'playlist_track.track_id')
            ->whereIn('playlist_id', $playlists->pluck('id'))
            ->whereNull('tracks.deleted_at')
            ->where('tracks.has_cover', true)
            ->orderBy('position')
            ->get(['playlist_id', 'track_id'])
            ->groupBy('playlist_id');

        foreach ($playlists as $playlist) {
            $playlist->preview_track_ids = ($rows[$playlist->id] ?? collect())->pluck('track_id')->unique()->take(4)->values()->all();
        }
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $playlists = Playlist::query()
            ->with(['user', 'collaborators'])
            ->withCount('tracks')
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhereHas('collaborators', fn ($c) => $c->whereKey($user->id))
                ->when($request->boolean('include_public'), fn ($q) => $q->orWhere('is_public', true)))
            ->orderByRaw('user_id = ? desc', [$user->id])
            ->latest('updated_at')
            ->get();
        self::attachPreviews($playlists);

        return PlaylistResource::collection($playlists);
    }

    public function store(Request $request): PlaylistResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['boolean'],
            'track_ids' => ['array'],
            'track_ids.*' => ['integer', 'exists:tracks,id'],
        ]);

        $playlist = $request->user()->playlists()->create($data);
        $this->appendTracks($playlist, $data['track_ids'] ?? [], false, $request->user()->id);

        return $this->show($request, $playlist);
    }

    public function show(Request $request, Playlist $playlist): PlaylistResource
    {
        $playlist->load(['user', 'collaborators']);
        abort_unless($playlist->isVisibleTo($request->user()), 404);
        $playlist->load('tracks');
        // Who added each song, shown in shared playlists only.
        if ($playlist->collaborators->isNotEmpty()) {
            $adders = User::query()->whereIn('id', $playlist->tracks->pluck('pivot.added_by')->filter()->unique())->get()->keyBy('id');
            $playlist->tracks->each(fn ($track) => $track->setAttribute('added_by_user', $adders->get($track->pivot->added_by)));
        }
        self::attachPreviews(collect([$playlist]));

        return new PlaylistResource($playlist);
    }

    public function update(Request $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeOwner($request, $playlist);
        $playlist->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_public' => ['sometimes', 'boolean'],
        ]));

        return $this->show($request, $playlist);
    }

    public function destroy(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorizeOwner($request, $playlist);
        if ($playlist->cover_path) {
            Storage::delete($playlist->cover_path);
        }
        $playlist->delete();

        return response()->json(['ok' => true]);
    }

    public function addTracks(Request $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeEditor($request, $playlist);
        $data = $request->validate([
            'track_ids' => ['required', 'array', 'max:1000'],
            'track_ids.*' => ['integer', 'exists:tracks,id'],
            'allow_duplicates' => ['boolean'],
        ]);
        $this->appendTracks($playlist, $data['track_ids'], ! ($data['allow_duplicates'] ?? false), $request->user()->id);

        return $this->show($request, $playlist);
    }

    public function removeTrack(Request $request, Playlist $playlist, int $entry): PlaylistResource
    {
        $this->authorizeEditor($request, $playlist);
        DB::table('playlist_track')->where('playlist_id', $playlist->id)->where('id', $entry)->delete();
        $playlist->touch();

        return $this->show($request, $playlist);
    }

    public function reorder(Request $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeEditor($request, $playlist);
        $ids = $request->validate([
            'entry_ids' => ['required', 'array'],
            'entry_ids.*' => ['integer'],
        ])['entry_ids'];

        DB::transaction(function () use ($playlist, $ids) {
            foreach ($ids as $position => $entryId) {
                DB::table('playlist_track')
                    ->where('playlist_id', $playlist->id)
                    ->where('id', $entryId)
                    ->update(['position' => $position]);
            }
        });
        $playlist->touch();

        return $this->show($request, $playlist);
    }

    public function uploadCover(Request $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeOwner($request, $playlist);
        $request->validate(['cover' => ['required', 'image', 'max:5120']]);
        if ($playlist->cover_path) {
            Storage::delete($playlist->cover_path);
        }
        $playlist->cover_path = $request->file('cover')->store('playlist-covers');
        $playlist->save();

        return $this->show($request, $playlist);
    }

    public function deleteCover(Request $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeOwner($request, $playlist);
        if ($playlist->cover_path) {
            Storage::delete($playlist->cover_path);
            $playlist->cover_path = null;
            $playlist->save();
        }

        return $this->show($request, $playlist);
    }

    public function cover(Request $request, Playlist $playlist)
    {
        abort_unless($playlist->isVisibleTo($request->user()) && $playlist->cover_path && Storage::exists($playlist->cover_path), 404);

        return response()->file(Storage::path($playlist->cover_path), ['Cache-Control' => 'private, max-age=604800']);
    }

    private function appendTracks(Playlist $playlist, array $trackIds, bool $skipExisting, ?int $addedBy = null): void
    {
        if ($skipExisting) {
            $existing = DB::table('playlist_track')->where('playlist_id', $playlist->id)->pluck('track_id')->all();
            $trackIds = array_values(array_diff(array_unique($trackIds), $existing));
        }
        $position = (int) DB::table('playlist_track')->where('playlist_id', $playlist->id)->max('position');
        $now = now();
        $rows = [];
        foreach ($trackIds as $trackId) {
            $rows[] = ['playlist_id' => $playlist->id, 'track_id' => $trackId, 'position' => ++$position, 'added_by' => $addedBy, 'created_at' => $now];
        }
        if ($rows) {
            DB::table('playlist_track')->insert($rows);
            $playlist->touch();
        }
    }

    private function authorizeOwner(Request $request, Playlist $playlist): void
    {
        abort_unless($playlist->user_id === $request->user()->id, 403);
    }

    private function authorizeEditor(Request $request, Playlist $playlist): void
    {
        abort_unless($playlist->canEditTracks($request->user()), 403);
    }

    /** The owner sets who may add songs (a shared playlist); an empty list makes it personal again. */
    public function setCollaborators(Request $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeOwner($request, $playlist);
        $ids = $request->validate([
            'user_ids' => ['present', 'array', 'max:100'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ])['user_ids'];
        $ids = array_values(array_diff(array_unique($ids), [$playlist->user_id]));
        $playlist->collaborators()->sync(array_fill_keys($ids, ['created_at' => now()]));
        $playlist->touch();

        return $this->show($request, $playlist);
    }

    /** A collaborator leaves a shared playlist. */
    public function leave(Request $request, Playlist $playlist): JsonResponse
    {
        $playlist->collaborators()->detach($request->user()->id);

        return response()->json(['ok' => true]);
    }

    /** Active members, to invite to a shared playlist. */
    public function members(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereKeyNot($request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'avatar_url' => $u->avatarUrl(),
        ])]);
    }
}
