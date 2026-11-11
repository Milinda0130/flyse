<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_log_in_and_fetch_profile(): void
    {
        User::factory()->owner()->create(['email' => 'owner@agency.test', 'password' => 'Secret12345']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'OWNER@agency.test', 'password' => 'Secret12345'])
            ->assertOk()
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('abilities.view-reports', true);

        $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.email', 'owner@agency.test');
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'a@agency.test', 'password' => 'Secret12345']);
        $this->postJson('/api/v1/auth/login', ['email' => 'a@agency.test', 'password' => 'nope'])->assertStatus(422);
    }

    public function test_inactive_user_cannot_log_in_and_existing_tokens_stop_working(): void
    {
        $user = User::factory()->create(['email' => 'b@agency.test', 'password' => 'Secret12345']);
        $token = $user->createToken('web')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'b@agency.test', 'password' => 'Secret12345'])->assertStatus(422);
        $this->withToken($token)->getJson('/api/v1/bookings')->assertForbidden();
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->getJson('/api/v1/bookings')->assertUnauthorized();
        $this->getJson('/api/v1/documents/1/download')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'c@agency.test']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'c@agency.test', 'password' => 'wrong']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'c@agency.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }
}
