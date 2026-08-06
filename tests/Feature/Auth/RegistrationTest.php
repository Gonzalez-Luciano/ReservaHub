<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
    }

    public function test_new_business_owner_can_register(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'business',
            'name' => 'Ana Owner',
            'email' => 'ana@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Peluquería Norte',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');

        $user = User::firstWhere('email', 'ana@example.com');
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame(Role::Owner, $user->role);
        $this->assertNotNull($user->business_id);
        $this->assertSame('Peluquería Norte', $user->business->name);
    }

    public function test_new_customer_can_register(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'customer',
            'name' => 'Carla Cliente',
            'email' => 'carla@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');

        $user = User::firstWhere('email', 'carla@example.com');
        $this->assertNotNull($user);
        $this->assertSame(Role::Customer, $user->role);
        $this->assertNull($user->business_id);
    }

    public function test_business_registration_requires_business_name(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'business',
            'name' => 'Ana Owner',
            'email' => 'ana@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('business_name');
        $this->assertGuest();
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'account_type' => 'customer',
            'name' => 'Otra Persona',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_business_slugs_are_unique(): void
    {
        Business::factory()->create(['name' => 'Peluquería Norte', 'slug' => 'peluqueria-norte']);

        $this->post('/register', [
            'account_type' => 'business',
            'name' => 'Otro Owner',
            'email' => 'otro@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Peluquería Norte',
        ]);

        $user = User::firstWhere('email', 'otro@example.com');
        $this->assertNotSame('peluqueria-norte', $user->business->slug);
    }
}
