<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('username', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderByRaw("status = 'pending' desc")
            ->latest()
            ->get();

        return UserResource::collection($users);
    }

    public function store(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_.]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MEMBER])],
        ]);

        $user = User::query()->create([
            ...$data,
            'username' => strtolower($data['username']),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->forceFill(['approved_at' => now(), 'approved_by' => $request->user()->id])->save();

        return new UserResource($user);
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_MEMBER])],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED, User::STATUS_PENDING])],
            'password' => ['sometimes', 'string', Password::min(8)],
        ]);

        if ($user->is($request->user()) && (($data['role'] ?? 'admin') !== 'admin' || ($data['status'] ?? 'active') !== 'active')) {
            throw ValidationException::withMessages(['role' => 'You cannot demote or disable yourself.']);
        }

        if (($data['status'] ?? null) === User::STATUS_ACTIVE && ! $user->isActive()) {
            $user->forceFill(['approved_at' => now(), 'approved_by' => $request->user()->id]);
        }
        $user->fill($data)->save();

        if (! $user->isActive() || isset($data['password'])) {
            $user->tokens()->delete();
        }

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'You cannot delete yourself.']);
        }
        $user->deleteAvatar();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['ok' => true]);
    }
}
