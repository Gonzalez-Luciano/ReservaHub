<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_without_a_business_can_open_the_account_page(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)->get('/account')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Edit')
                ->where('user.email', $customer->email));
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/account')->assertRedirect('/login');
    }

    public function test_it_updates_the_name_without_touching_verification(): void
    {
        Notification::fake();

        $user = User::factory()->customer()->create(['name' => 'Nombre viejo']);
        $verifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => 'Nombre nuevo', 'email' => $user->email])
            ->assertRedirect('/account');

        $user->refresh();

        $this->assertSame('Nombre nuevo', $user->name);
        $this->assertEquals($verifiedAt, $user->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_changing_the_email_clears_verification_and_sends_a_new_link(): void
    {
        Notification::fake();

        $user = User::factory()->customer()->create();

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => $user->name, 'email' => 'nuevo@example.com'])
            ->assertRedirect('/account');

        $user->refresh();

        $this->assertSame('nuevo@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_it_rejects_an_email_already_taken_by_someone_else(): void
    {
        $user = User::factory()->customer()->create();
        $otherUser = User::factory()->customer()->create();

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => $user->name, 'email' => $otherUser->email])
            ->assertSessionHasErrors('email');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_it_accepts_the_users_own_email_unchanged(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => 'Otro nombre', 'email' => $user->email])
            ->assertSessionHasNoErrors();
    }
}
