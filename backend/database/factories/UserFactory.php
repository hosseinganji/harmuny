<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => strtolower(Str::random(10)),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'role' => User::ROLE_MEMBER,
            'status' => User::STATUS_ACTIVE,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_PENDING]);
    }
}
