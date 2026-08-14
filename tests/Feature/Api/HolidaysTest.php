<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidaysTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('cli')->plainTextToken;
    }

    /**
     * @return array{0: Business, 1: User}
     */
    private function businessWithOwner(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        return [$business, $owner];
    }

    public function test_it_lists_and_creates_holidays(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $token = $this->tokenFor($owner);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/holidays', [
                'name' => 'Navidad',
                'starts_on' => '2026-12-25',
                'ends_on' => '2026-12-25',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Navidad')
            ->assertJsonPath('message', 'Feriado creado.');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/holidays')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_it_deletes_a_holiday(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $holiday = BusinessHoliday::factory()->create(['business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/holidays/{$holiday->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Feriado eliminado.');

        $this->assertDatabaseMissing('business_holidays', ['id' => $holiday->id]);
    }

    public function test_a_conflict_returns_the_validation_envelope_with_the_preview(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);
        $startsAt = CarbonImmutable::parse('2026-12-25 10:00:00', 'UTC');

        Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/holidays', [
                'name' => 'Navidad',
                'starts_on' => '2026-12-25',
                'ends_on' => '2026-12-25',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Los datos enviados no son válidos.')
            ->assertJsonStructure(['errors' => ['starts_on', 'bookings_preview']]);

        $this->assertDatabaseCount('business_holidays', 0);
    }

    public function test_an_employee_is_forbidden(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($employee))
            ->getJson('/api/holidays')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'No tenés permiso para realizar esta acción.']);
    }

    public function test_deleting_a_holiday_from_another_business_returns_404(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $foreignHoliday = BusinessHoliday::factory()->create([
            'business_id' => Business::factory()->create()->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/holidays/{$foreignHoliday->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Recurso no encontrado.']);

        $this->assertDatabaseHas('business_holidays', ['id' => $foreignHoliday->id]);
    }
}
