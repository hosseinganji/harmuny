<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\MediaKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_.]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        // The very first account bootstraps the instance as an active admin.
        $first = ! User::query()->exists();

        $user = User::query()->create([
            ...$data,
            'username' => strtolower($data['username']),
            'role' => $first ? User::ROLE_ADMIN : User::ROLE_MEMBER,
            'status' => $first ? User::STATUS_ACTIVE : User::STATUS_PENDING,
        ]);

        if ($first) {
            $user->forceFill(['approved_at' => now()])->save();

            return $this->issueToken($request, $user, 201);
        }

        return response()->json([
            'status' => User::STATUS_PENDING,
            'message' => 'Registration received. An admin needs to approve your account.',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:100'],
        ]);

        $throttleKey = 'login:'.strtolower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            throw ValidationException::withMessages([
                'username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        $login = strtolower(trim($data['username']));
        $user = User::query()
            ->where('username', $login)
            ->orWhere('email', $login)
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);
            throw ValidationException::withMessages(['username' => 'Wrong username or password.']);
        }
        RateLimiter::clear($throttleKey);

        if (! $user->isActive()) {
            return response()->json([
                'status' => $user->status,
                'message' => $user->status === User::STATUS_PENDING
                    ? 'Your account is waiting for admin approval.'
                    : 'Your account has been disabled.',
            ], 403);
        }

        return $this->issueToken($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->sessionPayload($request->user()));
    }

    private function issueToken(Request $request, User $user, int $status = 200): JsonResponse
    {
        $device = $request->input('device') ?: mb_substr((string) $request->userAgent(), 0, 100) ?: 'device';
        $token = $user->createToken($device)->plainTextToken;

        return response()->json(['token' => $token, ...$this->sessionPayload($user)], $status);
    }

    public static function sessionPayload(User $user): array
    {
        return [
            'user' => (new UserResource($user))->resolve(request()),
            'preferences' => (object) ($user->preferences ?? []),
            'media_key' => MediaKey::for($user),
        ];
    }
}
