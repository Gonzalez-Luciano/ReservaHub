<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_assign_services_to_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $serviceA = Service::factory()->for($business)->create();
        $serviceB = Service::factory()->for($business)->create();

        $response = $this->actingAs($owner)->put("/dashboard/employees/{$employee->id}/services", [
            'service_ids' => [$serviceA->id, $serviceB->id],
        ]);

        $response->assertRedirect('/dashboard/employees');
        $this->assertSame(
            [$serviceA->id, $serviceB->id],
            $employee->services()->pluck('services.id')->sort()->values()->all(),
        );
    }

    public function test_cannot_assign_service_from_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeA = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessA->id]);
        $serviceB = Service::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA)->put("/dashboard/employees/{$employeeA->id}/services", [
            'service_ids' => [$serviceB->id],
        ]);

        $response->assertInvalid(['service_ids']);
        $this->assertSame(0, $employeeA->services()->count());
    }

    public function test_cannot_assign_services_to_employee_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);

        $this->actingAs($ownerA)->put("/dashboard/employees/{$employeeB->id}/services", ['service_ids' => []])
            ->assertNotFound();
    }

    public function test_employee_cannot_assign_own_services(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->put("/dashboard/employees/{$employee->id}/services", ['service_ids' => []])
            ->assertForbidden();
    }
}
