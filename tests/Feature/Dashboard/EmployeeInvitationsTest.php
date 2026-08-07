<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Notifications\EmployeeInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_employee(): void
    {
        Notification::fake();

        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post('/dashboard/employees/invitations', [
            'email' => 'nuevo@example.com',
            'name' => 'Nuevo Empleado',
        ]);

        $response->assertRedirect('/dashboard/employees');
        $this->assertDatabaseHas('employee_invitations', [
            'business_id' => $business->id,
            'email' => 'nuevo@example.com',
        ]);
        Notification::assertSentOnDemand(EmployeeInvited::class);
    }

    public function test_cannot_invite_same_email_twice_while_pending(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        EmployeeInvitation::factory()->for($business)->create([
            'email' => 'dup@example.com',
            'invited_by_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->post('/dashboard/employees/invitations', [
            'email' => 'dup@example.com',
        ]);

        $response->assertInvalid(['email']);
    }

    public function test_cannot_invite_email_already_registered_as_user(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($owner)->post('/dashboard/employees/invitations', [
            'email' => 'existing@example.com',
        ]);

        $response->assertInvalid(['email']);
    }

    public function test_employee_cannot_invite(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->post('/dashboard/employees/invitations', ['email' => 'x@example.com'])
            ->assertForbidden();
    }

    public function test_owner_can_resend_and_revoke_invitation(): void
    {
        Notification::fake();

        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->for($business)->create(['invited_by_id' => $owner->id]);
        $originalToken = $invitation->token;

        $this->actingAs($owner)->post("/dashboard/employees/invitations/{$invitation->id}/resend")
            ->assertRedirect('/dashboard/employees');
        $this->assertNotSame($originalToken, $invitation->fresh()->token);

        $this->actingAs($owner)->delete("/dashboard/employees/invitations/{$invitation->id}")
            ->assertRedirect('/dashboard/employees');
        $this->assertDatabaseMissing('employee_invitations', ['id' => $invitation->id]);
    }

    public function test_owner_cannot_manage_invitation_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $ownerB = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessB->id]);
        $invitationB = EmployeeInvitation::factory()->for($businessB)->create(['invited_by_id' => $ownerB->id]);

        $this->actingAs($ownerA)->post("/dashboard/employees/invitations/{$invitationB->id}/resend")
            ->assertNotFound();
        $this->actingAs($ownerA)->delete("/dashboard/employees/invitations/{$invitationB->id}")
            ->assertNotFound();
    }

    public function test_employees_index_only_lists_own_business_employees(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        User::factory()->create(['role' => Role::Employee, 'business_id' => $businessA->id, 'name' => 'Ana']);
        User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id, 'name' => 'Beto']);

        $this->actingAs($ownerA)->get('/dashboard/employees')
            ->assertInertia(fn ($page) => $page
                ->has('employees', 1)
                ->where('employees.0.name', 'Ana'));
    }
}
