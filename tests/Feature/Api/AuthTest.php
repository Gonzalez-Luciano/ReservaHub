<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_token_and_the_user(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->owner()->create([
            'business_id' => $business->id,
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'owner')
            ->assertJsonStructure(['success', 'data' => ['token', 'user'], 'message', 'errors']);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->customer()->create(['email' => 'cliente@example.com', 'password' => 'password']);

        $this->postJson('/api/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'incorrecta',
            'device_name' => 'phpunit',
        ])->assertStatus(401)->assertJsonPath('success', false)->assertJsonPath('data', null);
    }

    public function test_login_fails_for_an_inactive_user(): void
    {
        User::factory()->customer()->create([
            'email' => 'cliente@example.com',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertStatus(401);
    }

    public function test_login_fails_when_the_business_is_inactive(): void
    {
        $business = Business::factory()->create(['is_active' => false]);
        User::factory()->owner()->create([
            'business_id' => $business->id,
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertStatus(401);
    }

    public function test_login_requires_a_device_name(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['device_name']]);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->customer()->create();
        $current = $user->createToken('actual')->plainTextToken;
        $user->createToken('otro-dispositivo');

        $this->withHeader('Authorization', 'Bearer '.$current)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('otro-dispositivo', $user->tokens()->first()->name);
    }

    public function test_protected_routes_reject_requests_without_a_token(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No autenticado.');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'nadie@example.com',
                'password' => 'incorrecta',
                'device_name' => 'phpunit',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'nadie@example.com',
            'password' => 'incorrecta',
            'device_name' => 'phpunit',
        ])->assertStatus(429)->assertJsonPath('success', false);
    }
}
