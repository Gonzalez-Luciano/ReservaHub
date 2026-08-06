<?php

namespace Tests\Unit\Actions\Auth;

use App\Actions\Auth\RegisterBusinessOwner;
use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterBusinessOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_business_and_owner(): void
    {
        $user = (new RegisterBusinessOwner)->handle(
            name: 'Ana Owner',
            email: 'ana@example.com',
            password: 'password',
            businessName: 'Peluquería Norte',
        );

        $this->assertSame(Role::Owner, $user->role);
        $this->assertNotNull($user->business_id);
        $this->assertSame('Peluquería Norte', $user->business->name);
    }

    public function test_rolls_back_business_when_user_creation_fails(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        try {
            (new RegisterBusinessOwner)->handle(
                name: 'Otra Persona',
                email: 'taken@example.com',
                password: 'password',
                businessName: 'Otro Negocio',
            );
            $this->fail('Expected a QueryException for the duplicate email.');
        } catch (QueryException $e) {
            // expected: unique constraint on users.email
        }

        $this->assertSame(0, Business::where('name', 'Otro Negocio')->count());
    }
}
