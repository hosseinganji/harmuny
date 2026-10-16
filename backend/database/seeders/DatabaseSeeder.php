<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates the admin account from ADMIN_USERNAME / ADMIN_PASSWORD when they are set. */
    public function run(): void
    {
        $admin = config('music.admin');
        if (! $admin['username'] || ! $admin['password']) {
            $this->command?->info('ADMIN_USERNAME/ADMIN_PASSWORD not set; the first account to register becomes admin.');

            return;
        }

        $user = User::query()->firstOrNew(['username' => strtolower($admin['username'])]);
        $user->fill([
            'name' => $user->name ?? $admin['name'],
            'password' => $admin['password'],
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->approved_at ??= now();
        $user->save();
    }
}
