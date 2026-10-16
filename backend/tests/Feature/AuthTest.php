<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_user_becomes_active_admin(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Hossein', 'username' => 'Hossein', 'password' => 'secret-pass',
        ]);

        $response->assertCreated()->assertJsonPath('user.role', 'admin')->assertJsonStructure(['token', 'media_key']);
        $this->assertSame('hossein', User::first()->username);
    }

    public function test_later_users_wait_for_approval(): void
    {
        $admin = User::factory()->admin()->create();

        $this->postJson('/api/auth/register', [
            'name' => 'Sara', 'username' => 'sara', 'password' => 'secret-pass',
        ])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonMissingPath('token');

        $this->postJson('/api/auth/login', ['username' => 'sara', 'password' => 'secret-pass'])
            ->assertForbidden()->assertJsonPath('status', 'pending');

        $sara = User::where('username', 'sara')->first();
        $this->actingAs($admin)->patchJson("/api/admin/users/{$sara->id}", ['status' => 'active'])->assertOk();

        $this->postJson('/api/auth/login', ['username' => 'sara', 'password' => 'secret-pass'])
            ->assertOk()->assertJsonStructure(['token', 'user', 'media_key']);
        $this->assertSame($admin->id, $sara->fresh()->approved_by);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'ali']);

        $this->postJson('/api/auth/login', ['username' => 'ali', 'password' => 'nope-nope'])
            ->assertUnprocessable();
    }

    public function test_members_cannot_reach_admin_routes(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_disabled_user_token_stops_working(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')->assertOk();

        $user->update(['status' => User::STATUS_DISABLED]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertForbidden();
    }

    public function test_admin_cannot_demote_self(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", ['role' => 'member'])
            ->assertUnprocessable();
    }

    public function test_profile_update_merges_preferences(): void
    {
        $user = User::factory()->create(['preferences' => ['theme' => 'dark']]);

        $this->actingAs($user)->patchJson('/api/me', ['name' => 'New', 'preferences' => ['accent' => '#ff0066']])
            ->assertOk()
            ->assertJsonPath('user.name', 'New')
            ->assertJsonPath('preferences.theme', 'dark')
            ->assertJsonPath('preferences.accent', '#ff0066');
    }
}
