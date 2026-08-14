<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    private function insertSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_it_rejects_a_wrong_current_password(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'incorrecta',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'contrasena-nueva-1',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('contrasena-vieja', $user->fresh()->password));
    }

    public function test_it_rejects_an_unconfirmed_password(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'contrasena-vieja',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'otra-cosa',
        ])->assertSessionHasErrors('password');
    }

    public function test_it_changes_the_password_and_revokes_other_access(): void
    {
        $user = User::factory()->customer()->create([
            'password' => 'contrasena-vieja',
            'remember_token' => 'token-original',
        ]);
        $otherUser = User::factory()->customer()->create();
        $user->createToken('cli');

        $this->insertSession('otro-dispositivo', $user->id);
        $this->insertSession('otro-usuario', $otherUser->id);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'contrasena-vieja',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'contrasena-nueva-1',
        ])->assertRedirect('/account');

        $user->refresh();

        $this->assertTrue(Hash::check('contrasena-nueva-1', $user->password));
        $this->assertNotSame('token-original', $user->remember_token);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'otro-dispositivo']);
        $this->assertDatabaseHas('sessions', ['id' => 'otro-usuario']);
    }

    public function test_the_user_stays_authenticated_after_changing_the_password(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'contrasena-vieja',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'contrasena-nueva-1',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->get('/account')->assertOk();
    }
}
