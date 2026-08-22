<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_without_a_token_is_rejected(): void
    {
        Business::factory()->create();

        $this->getJson('/api/businesses')->assertUnauthorized();
    }

    public function test_a_customer_lists_the_active_businesses_ordered_by_name(): void
    {
        Business::factory()->create(['name' => 'Zapatería Zoe']);
        Business::factory()->create(['name' => 'Barbería Ana']);
        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson('/api/businesses')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Barbería Ana')
            ->assertJsonPath('data.1.name', 'Zapatería Zoe')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'timezone', 'currency', 'cancellation_hours']]]);
    }

    public function test_an_inactive_business_is_not_listed(): void
    {
        Business::factory()->create(['name' => 'Negocio Activo', 'is_active' => true]);
        Business::factory()->create(['name' => 'Negocio Inactivo', 'is_active' => false]);
        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson('/api/businesses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Negocio Activo');
    }
}
