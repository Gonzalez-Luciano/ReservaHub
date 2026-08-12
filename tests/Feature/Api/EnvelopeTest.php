<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_responses_have_exactly_the_four_keys(): void
    {
        $business = Business::factory()->create();
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $payload = $this->getJson('/api/services')->assertOk()->json();

        $this->assertSame(['success', 'data', 'message', 'errors'], array_keys($payload));
        $this->assertTrue($payload['success']);
        $this->assertNull($payload['errors']);
    }

    public function test_validation_errors_use_the_envelope(): void
    {
        $business = Business::factory()->create();
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $payload = $this->getJson('/api/availability')->assertStatus(422)->json();

        $this->assertSame(['success', 'data', 'message', 'errors'], array_keys($payload));
        $this->assertFalse($payload['success']);
        $this->assertNull($payload['data']);
        $this->assertArrayHasKey('service_id', $payload['errors']);
    }

    public function test_not_found_uses_the_envelope(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson('/api/bookings/999999')
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => 'Recurso no encontrado.',
                'errors' => null,
            ]);
    }

    public function test_forbidden_uses_the_envelope(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson('/api/services')
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => 'No tenés permiso para realizar esta acción.',
                'errors' => null,
            ]);
    }
}
