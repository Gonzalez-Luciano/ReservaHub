<?php

namespace Tests\Feature\Dashboard;

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

    /**
     * @return array{0: Business, 1: User}
     */
    private function businessWithOwner(string $timezone = 'UTC'): array
    {
        $business = Business::factory()->create(['timezone' => $timezone]);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        return [$business, $owner];
    }

    private function bookingFor(
        Business $business,
        CarbonImmutable $startsAt,
        int $durationMinutes = 30,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => $durationMinutes]);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($durationMinutes),
            'status' => $status,
        ]);
    }

    public function test_an_owner_lists_and_creates_a_holiday(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertRedirect('/dashboard/holidays');

        $this->assertDatabaseHas('business_holidays', [
            'business_id' => $business->id,
            'name' => 'Navidad',
        ]);

        $this->actingAs($owner)->get('/dashboard/holidays')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Holidays/Index')
                ->has('holidays', 1));
    }

    public function test_an_owner_deletes_a_holiday(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $holiday = BusinessHoliday::factory()->create(['business_id' => $business->id]);

        $this->actingAs($owner)->delete("/dashboard/holidays/{$holiday->id}")
            ->assertRedirect('/dashboard/holidays');

        $this->assertDatabaseMissing('business_holidays', ['id' => $holiday->id]);
    }

    public function test_it_rejects_an_end_date_before_the_start_date(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Rango inválido',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-24',
        ])->assertSessionHasErrors('ends_on');
    }

    public function test_it_rejects_a_holiday_overlapping_another_one(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => '2026-12-24',
            'ends_on' => '2026-12-26',
        ]);

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Superpuesto',
            'starts_on' => '2026-12-26',
            'ends_on' => '2026-12-28',
        ])->assertSessionHasErrors('starts_on');

        $this->assertDatabaseCount('business_holidays', 1);
    }

    public function test_a_booking_starting_before_the_holiday_but_ending_inside_it_blocks_creation(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        // El feriado empieza el 25/12 00:00 UTC. Esta reserva arranca el 24/12
        // a las 23:45 y termina el 25/12 a las 00:15: su `starts_at` cae fuera,
        // pero el intervalo se solapa.
        $this->bookingFor($business, CarbonImmutable::parse('2026-12-24 23:45:00', 'UTC'), 30);

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertSessionHasErrors('starts_on');

        $this->assertDatabaseCount('business_holidays', 0);
    }

    public function test_a_cancelled_booking_does_not_block_creation(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->bookingFor(
            $business,
            CarbonImmutable::parse('2026-12-25 10:00:00', 'UTC'),
            30,
            BookingStatus::Cancelled,
        );

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('business_holidays', 1);
    }

    public function test_the_conflict_response_carries_the_total_and_a_preview_capped_at_five(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        foreach (range(0, 5) as $offset) {
            $this->bookingFor($business, CarbonImmutable::parse('2026-12-25 09:00:00', 'UTC')->addHours($offset));
        }

        $response = $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ]);

        $response->assertSessionHasErrors(['starts_on', 'bookings_preview']);

        $errors = session('errors');

        $this->assertStringContainsString('6', $errors->first('starts_on'));
        $this->assertCount(5, $errors->get('bookings_preview'));
    }

    public function test_a_holiday_in_another_timezone_uses_the_business_local_day(): void
    {
        // Buenos Aires es UTC-3: el 25/12 local va de 03:00 a 03:00 UTC del 26.
        // Una reserva a las 02:00 UTC del 25 todavía pertenece al 24 local y no
        // debe bloquear el feriado.
        [$business, $owner] = $this->businessWithOwner('America/Argentina/Buenos_Aires');

        $this->bookingFor($business, CarbonImmutable::parse('2026-12-25 02:00:00', 'UTC'), 30);

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_employee_is_forbidden(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->get('/dashboard/holidays')->assertForbidden();

        $this->actingAs($employee)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertForbidden();
    }

    public function test_a_holiday_from_another_business_returns_404_not_403(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $otherBusiness = Business::factory()->create();
        $foreignHoliday = BusinessHoliday::factory()->create(['business_id' => $otherBusiness->id]);

        // El global scope filtra el query del route-model binding, así que el
        // recurso ajeno ni siquiera se resuelve.
        $this->actingAs($owner)->delete("/dashboard/holidays/{$foreignHoliday->id}")->assertNotFound();

        $this->assertDatabaseHas('business_holidays', ['id' => $foreignHoliday->id]);
    }
}
