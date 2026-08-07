<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_schedule_for_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $response->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseHas('schedules', [
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => 1,
        ]);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '09:00',
        ])->assertInvalid(['end_time']);
    }

    public function test_cannot_create_two_schedules_same_day_for_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        Schedule::factory()->for($business)->create(['employee_id' => $employee->id, 'day_of_week' => 1]);

        $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertInvalid(['day_of_week']);
    }

    public function test_owner_can_add_break_within_schedule_range(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create([
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $response = $this->actingAs($owner)->post("/dashboard/schedules/{$schedule->id}/breaks", [
            'start_time' => '13:00',
            'end_time' => '14:00',
        ]);

        $response->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseHas('schedule_breaks', ['schedule_id' => $schedule->id]);
    }

    public function test_break_outside_schedule_range_is_rejected(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create([
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $this->actingAs($owner)->post("/dashboard/schedules/{$schedule->id}/breaks", [
            'start_time' => '08:00',
            'end_time' => '09:30',
        ])->assertInvalid(['start_time']);
    }

    public function test_break_exactly_at_schedule_boundaries_is_accepted(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create([
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $this->actingAs($owner)->post("/dashboard/schedules/{$schedule->id}/breaks", [
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
    }

    public function test_owner_can_delete_schedule_and_break(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create(['employee_id' => $employee->id]);
        $break = $schedule->breaks()->create(['start_time' => '13:00', 'end_time' => '14:00']);

        $this->actingAs($owner)->delete("/dashboard/schedule-breaks/{$break->id}")
            ->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseMissing('schedule_breaks', ['id' => $break->id]);

        $this->actingAs($owner)->delete("/dashboard/schedules/{$schedule->id}")
            ->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
    }

    public function test_owner_cannot_manage_schedule_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);
        $scheduleB = Schedule::factory()->for($businessB)->create(['employee_id' => $employeeB->id]);

        $this->actingAs($ownerA)->get("/dashboard/employees/{$employeeB->id}/schedule")->assertNotFound();
        $this->actingAs($ownerA)->put("/dashboard/schedules/{$scheduleB->id}", [
            'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '18:00',
        ])->assertNotFound();
    }

    public function test_employee_cannot_manage_schedules(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00',
        ])->assertForbidden();
    }

    public function test_invalid_day_of_week_returns_validation_error_not_500(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 'abc',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertInvalid(['day_of_week']);
    }

    public function test_owner_cannot_delete_schedule_break_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);
        $scheduleB = Schedule::factory()->for($businessB)->create(['employee_id' => $employeeB->id]);
        $breakB = $scheduleB->breaks()->create(['start_time' => '13:00', 'end_time' => '14:00']);

        $this->actingAs($ownerA)->delete("/dashboard/schedule-breaks/{$breakB->id}")
            ->assertForbidden();
    }
}
