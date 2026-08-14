<?php

namespace Tests\Feature\Account;

use App\Exceptions\UnsupportedSessionDriverException;
use App\Models\User;
use App\Support\UserAccessRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserAccessRevokerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_revokes_sessions_tokens_and_remember_token_under_the_database_driver(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create(['remember_token' => 'token-original']);
        $otherUser = User::factory()->create();
        $user->createToken('cli');

        $this->insertSession('sesion-actual', $user->id);
        $this->insertSession('otro-dispositivo', $user->id);
        $this->insertSession('otro-usuario', $otherUser->id);

        app(UserAccessRevoker::class)->revoke($user, 'sesion-actual');

        $this->assertDatabaseHas('sessions', ['id' => 'sesion-actual']);
        $this->assertDatabaseMissing('sessions', ['id' => 'otro-dispositivo']);
        $this->assertDatabaseHas('sessions', ['id' => 'otro-usuario']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotSame('token-original', $user->fresh()->remember_token);
    }

    public function test_it_removes_every_session_when_no_session_is_preserved(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create();
        $this->insertSession('sesion-actual', $user->id);
        $this->insertSession('otro-dispositivo', $user->id);

        app(UserAccessRevoker::class)->revoke($user);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_it_fails_closed_on_a_non_database_session_driver(): void
    {
        config()->set('session.driver', 'array');

        $user = User::factory()->create(['remember_token' => 'token-original']);
        $user->createToken('cli');
        $this->insertSession('sesion-actual', $user->id);

        $this->expectException(UnsupportedSessionDriverException::class);

        try {
            app(UserAccessRevoker::class)->revoke($user);
        } finally {
            $this->assertSame('token-original', $user->fresh()->remember_token);
            $this->assertSame(1, $user->tokens()->count());
            $this->assertDatabaseHas('sessions', ['id' => 'sesion-actual']);
        }
    }
}
