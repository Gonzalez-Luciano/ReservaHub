<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_only_sees_services_of_its_own_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        Service::factory()->for($business)->create(['name' => 'Corte', 'is_active' => true]);
        Service::factory()->for($other)->create(['name' => 'Masaje', 'is_active' => true]);

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Corte')
            ->assertJsonStructure(['data' => [['id', 'name', 'duration_minutes', 'buffer_minutes', 'price', 'deposit_amount', 'is_active']]]);
    }

    public function test_inactive_services_are_hidden(): void
    {
        $business = Business::factory()->create();
        Service::factory()->for($business)->create(['is_active' => false]);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/services')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_reads_services_through_the_business_slug(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        Service::factory()->for($business)->create(['name' => 'Corte', 'is_active' => true]);
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/businesses/barberia-juan/services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Corte');
    }

    public function test_unknown_slug_returns_404_with_envelope(): void
    {
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/businesses/no-existe/services')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_services_require_a_token(): void
    {
        $this->getJson('/api/services')->assertStatus(401);
    }
}
