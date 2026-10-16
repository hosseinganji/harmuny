<?php

namespace App\Support;

use App\Models\User;

/**
 * Stateless key for media URLs (<audio src>, <img src>) that cannot carry an
 * Authorization header. It is tied to the password hash, so changing the
 * password revokes every key issued before.
 */
class MediaKey
{
    public static function for(User $user): string
    {
        return $user->id.'.'.self::signature($user);
    }

    public static function resolve(?string $key): ?User
    {
        if (! $key || ! str_contains($key, '.')) {
            return null;
        }
        [$id, $signature] = explode('.', $key, 2);
        $user = User::query()->find((int) $id);

        return $user && hash_equals(self::signature($user), $signature) ? $user : null;
    }

    private static function signature(User $user): string
    {
        return substr(hash_hmac('sha256', $user->id.'|'.$user->password, config('app.key')), 0, 32);
    }
}
