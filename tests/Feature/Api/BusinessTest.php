<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('cli')->plainTextToken;
    }

    public function test_an_owner_reads_the_business(): void
    {
        $business = Business::factory()->create(['name' => 'Peluquería Vieja']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/business')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['name' => 'Peluquería Vieja', 'slug' => $business->slug],
                'errors' => null,
            ]);
    }

    public function test_an_owner_updates_the_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson('/api/business', [
                'name' => 'Peluquería Nueva',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'currency' => 'ARS',
                'cancellation_hours' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Peluquería Nueva')
            ->assertJsonPath('message', 'Ajustes actualizados.');

        $this->assertSame(6, $business->fresh()->cancellation_hours);
    }

    public function test_an_employee_is_forbidden(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($employee))
            ->putJson('/api/business', [
                'name' => 'Peluquería Nueva',
                'timezone' => 'UTC',
                'currency' => 'USD',
                'cancellation_hours' => 6,
            ])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'No tenés permiso para realizar esta acción.']);
    }

    public function test_a_customer_without_a_business_is_forbidden(): void
    {
        $customer = User::factory()->customer()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->getJson('/api/business')
            ->assertStatus(403);
    }

    public function test_an_unsupported_currency_returns_the_validation_envelope(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson('/api/business', [
                'name' => 'Peluquería Nueva',
                'timezone' => 'UTC',
                'currency' => 'ABC',
                'cancellation_hours' => 6,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Los datos enviados no son válidos.')
            ->assertJsonStructure(['errors' => ['currency']]);
    }
}
