<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\WithDatabaseSessions;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;
    use WithDatabaseSessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWithDatabaseSessions();
    }

    public function test_it_returns_the_authenticated_account(): void
    {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/account')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['id' => $user->id, 'email' => $user->email, 'role' => 'customer'],
                'errors' => null,
            ]);
    }

    public function test_it_updates_the_profile_over_the_api(): void
    {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/account/profile', ['name' => 'Nombre nuevo', 'email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre nuevo');
    }

    public function test_changing_the_password_revokes_the_token_used_for_the_request(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/account/password', [
                'current_password' => 'contrasena-vieja',
                'password' => 'contrasena-nueva-1',
                'password_confirmation' => 'contrasena-nueva-1',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => null,
                'message' => 'Contraseña actualizada. Todos los tokens fueron revocados; iniciá sesión de nuevo.',
                'errors' => null,
            ]);

        $this->assertTrue(Hash::check('contrasena-nueva-1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/account')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'No autenticado.']);
    }

    public function test_it_rejects_a_wrong_current_password_with_the_error_envelope(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/account/password', [
                'current_password' => 'incorrecta',
                'password' => 'contrasena-nueva-1',
                'password_confirmation' => 'contrasena-nueva-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.current_password.0', 'La contraseña actual no es correcta.');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_guest_gets_the_unauthenticated_envelope(): void
    {
        $this->getJson('/api/account')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'No autenticado.']);
    }
}
