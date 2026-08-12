<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_employees_of_the_business(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id, 'name' => 'Ana']);
        User::factory()->employee()->create(['business_id' => $business->id, 'is_active' => false]);
        User::factory()->employee()->create();

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee->id)
            ->assertJsonPath('data.0.name', 'Ana');
    }

    public function test_filters_employees_by_service(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        $service = Service::factory()->for($business)->create();
        $withService = User::factory()->employee()->create(['business_id' => $business->id]);
        User::factory()->employee()->create(['business_id' => $business->id]);
        $service->employees()->attach($withService->id);

        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson("/api/businesses/barberia-juan/employees?service_id={$service->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withService->id);
    }

    public function test_staff_employees_endpoint_never_exposes_employee_email(): void
    {
        $business = Business::factory()->create();
        User::factory()->employee()->create(['business_id' => $business->id]);

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonMissingPath('data.0.email');
    }

    public function test_public_employees_endpoint_never_exposes_employee_email(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-ana']);
        User::factory()->employee()->create(['business_id' => $business->id]);

        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/businesses/barberia-ana/employees')
            ->assertOk()
            ->assertJsonMissingPath('data.0.email');
    }
}
