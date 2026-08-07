<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_service(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post('/dashboard/services', [
            'name' => 'Corte de cabello',
            'description' => 'Corte clásico',
            'duration_minutes' => 30,
            'buffer_minutes' => 5,
            'price' => 25.5,
            'deposit_amount' => null,
            'is_active' => true,
        ]);

        $response->assertRedirect('/dashboard/services');
        $this->assertDatabaseHas('services', [
            'business_id' => $business->id,
            'name' => 'Corte de cabello',
        ]);
    }

    public function test_owner_can_list_and_edit_own_services(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();

        $this->actingAs($owner)->get('/dashboard/services')
            ->assertInertia(fn ($page) => $page->has('services', 1));

        $response = $this->actingAs($owner)->put("/dashboard/services/{$service->id}", [
            'name' => 'Nuevo nombre',
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'buffer_minutes' => $service->buffer_minutes,
            'price' => $service->price,
            'deposit_amount' => null,
            'is_active' => true,
        ]);

        $response->assertRedirect('/dashboard/services');
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Nuevo nombre']);
    }

    public function test_employee_can_view_but_not_manage_services(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();

        $this->actingAs($employee)->get('/dashboard/services')->assertOk();

        $validPayload = [
            'name' => 'X',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 10,
            'deposit_amount' => null,
            'is_active' => true,
        ];

        $this->actingAs($employee)->post('/dashboard/services', $validPayload)->assertForbidden();
        $this->actingAs($employee)->put("/dashboard/services/{$service->id}", $validPayload)->assertForbidden();
        $this->actingAs($employee)->delete("/dashboard/services/{$service->id}")->assertForbidden();
    }

    public function test_owner_cannot_edit_or_delete_service_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $serviceB = Service::factory()->for($businessB)->create();

        $this->actingAs($ownerA)->get("/dashboard/services/{$serviceB->id}/edit")->assertNotFound();
        $this->actingAs($ownerA)->delete("/dashboard/services/{$serviceB->id}")->assertNotFound();
    }

    public function test_employee_does_not_see_inactive_services(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        Service::factory()->for($business)->create(['is_active' => true, 'name' => 'Activo']);
        Service::factory()->for($business)->create(['is_active' => false, 'name' => 'Inactivo']);

        $this->actingAs($employee)->get('/dashboard/services')
            ->assertInertia(fn ($page) => $page->has('services', 1)->where('services.0.name', 'Activo'));
    }
}
