<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Play;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_.]+$/', Rule::unique('users')->ignore($user->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:190', Rule::unique('users')->ignore($user->id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:300'],
            'preferences' => ['sometimes', 'array'],
        ]);

        if (isset($data['username'])) {
            $data['username'] = strtolower($data['username']);
        }
        if (isset($data['preferences'])) {
            // Merge so clients can patch one preference at a time.
            $data['preferences'] = array_merge($user->preferences ?? [], $data['preferences']);
        }
        $user->update($data);

        return response()->json(AuthController::sessionPayload($user));
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);
        $user = $request->user();
        $user->deleteAvatar();
        $user->avatar_path = $request->file('avatar')->store(config('music.avatars_dir'));
        $user->save();

        return response()->json(AuthController::sessionPayload($user));
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->deleteAvatar();
        $user->save();

        return response()->json(AuthController::sessionPayload($user));
    }

    public function avatar(User $user)
    {
        abort_unless($user->avatar_path && Storage::exists($user->avatar_path), 404);

        return response()->file(Storage::path($user->avatar_path), ['Cache-Control' => 'public, max-age=604800']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is wrong.']);
        }
        $user->update(['password' => $data['password']]);
        // Sign out every other device.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(AuthController::sessionPayload($user));
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()->id;

        return response()->json($request->user()->tokens()->latest('last_used_at')->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'current' => $t->id === $current,
        ]));
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->whereKey($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $since = now()->subDays((int) $request->integer('days', 30));

        $base = Play::query()->where('user_id', $userId)->where('played_at', '>=', $since);

        $topArtists = (clone $base)
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereNotNull('tracks.artist')
            ->select('tracks.artist', DB::raw('count(*) as plays'))
            ->groupBy('tracks.artist')
            ->orderByDesc('plays')
            ->limit(10)
            ->get();

        return response()->json([
            'plays' => (clone $base)->count(),
            'minutes' => (int) round((clone $base)->sum('seconds') / 60),
            'top_artists' => $topArtists,
            'liked' => DB::table('track_likes')->where('user_id', $userId)->count(),
            'playlists' => $request->user()->playlists()->count(),
        ]);
    }
}
