<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_a_valid_invitation_creates_an_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->for($business)->create([
            'invited_by_id' => $owner->id,
            'email' => 'ana@example.com',
        ]);

        $url = URL::temporarySignedRoute('invitations.accept', $invitation->expires_at, ['token' => $invitation->token]);
        $this->get($url)->assertOk();

        $response = $this->post("/invitations/{$invitation->token}/accept", [
            'token' => $invitation->token,
            'name' => 'Ana Empleada',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertDatabaseHas('users', [
            'email' => 'ana@example.com',
            'business_id' => $business->id,
            'role' => Role::Employee->value,
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertAuthenticated();
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->expired()->for($business)->create(['invited_by_id' => $owner->id]);

        $this->post("/invitations/{$invitation->token}/accept", [
            'token' => $invitation->token,
            'name' => 'X',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => $invitation->email]);
    }

    public function test_already_accepted_invitation_cannot_be_accepted_again(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->accepted()->for($business)->create(['invited_by_id' => $owner->id]);

        $this->post("/invitations/{$invitation->token}/accept", [
            'token' => $invitation->token,
            'name' => 'X',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->post('/invitations/not-a-real-token/accept', [
            'token' => 'not-a-real-token',
            'name' => 'X',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_show_rejects_unsigned_url(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->for($business)->create(['invited_by_id' => $owner->id]);

        $this->get("/invitations/{$invitation->token}/accept")->assertForbidden();
    }
}
