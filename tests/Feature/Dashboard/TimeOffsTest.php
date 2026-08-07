<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeOffsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_time_off_for_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addDay()->toDateTimeString(),
            'reason' => 'Vacaciones',
        ]);

        $response->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseHas('time_offs', [
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'reason' => 'Vacaciones',
        ]);
    }

    public function test_ends_at_must_be_after_starts_at(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->toDateTimeString(),
        ])->assertInvalid(['ends_at']);
    }

    public function test_owner_can_delete_time_off(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $timeOff = TimeOff::factory()->for($business)->create(['employee_id' => $employee->id]);

        $this->actingAs($owner)->delete("/dashboard/time-offs/{$timeOff->id}")
            ->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseMissing('time_offs', ['id' => $timeOff->id]);
    }

    public function test_owner_cannot_manage_time_off_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);
        $timeOffB = TimeOff::factory()->for($businessB)->create(['employee_id' => $employeeB->id]);

        $this->actingAs($ownerA)->post("/dashboard/employees/{$employeeB->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addDay()->toDateTimeString(),
        ])->assertNotFound();

        $this->actingAs($ownerA)->delete("/dashboard/time-offs/{$timeOffB->id}")->assertNotFound();
    }

    public function test_employee_cannot_manage_time_offs(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->post("/dashboard/employees/{$employee->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addDay()->toDateTimeString(),
        ])->assertForbidden();
    }
}
