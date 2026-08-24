<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes fijo: la elegibilidad depende del día de la semana.
        $this->travelTo(CarbonImmutable::parse('2026-01-05 12:00', 'UTC'));
    }

    private function businessWithSchedule(string $name, DayOfWeek $day): Business
    {
        $business = Business::factory()->create(['name' => $name, 'timezone' => 'UTC', 'is_active' => true]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id, 'is_active' => true]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => $day,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        return $business;
    }

    public function test_it_renders_without_a_timeline_when_no_business_qualifies(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home')->where('timeline', null));
    }

    public function test_it_skips_a_business_with_no_eligible_employee_today(): void
    {
        // "Alfa" ordena antes pero solo trabaja los martes; hoy es lunes.
        $this->businessWithSchedule('Alfa', DayOfWeek::Tuesday);
        $this->businessWithSchedule('Beta', DayOfWeek::Monday);

        $this->get('/')->assertInertia(fn ($page) => $page->where('timeline.business_name', 'Beta'));
    }

    public function test_an_inactive_business_never_qualifies(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $business->update(['is_active' => false]);

        $this->get('/')->assertInertia(fn ($page) => $page->where('timeline', null));
    }

    public function test_an_inactive_employee_does_not_make_a_business_eligible(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $business->users()->update(['is_active' => false]);

        $this->get('/')->assertInertia(fn ($page) => $page->where('timeline', null));
    }

    public function test_cancelled_bookings_are_not_occupied(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $employee = $business->users()->first();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => CarbonImmutable::parse('2026-01-05 10:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-01-05 10:30', 'UTC'),
            'status' => BookingStatus::Cancelled,
        ]);

        $this->get('/')->assertInertia(fn ($page) => $page->has('timeline.occupied', 0));
    }

    public function test_the_projection_leaks_nothing_private(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $employee = $business->users()->first();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'name' => 'Corte']);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => CarbonImmutable::parse('2026-01-05 10:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-01-05 10:30', 'UTC'),
            'status' => BookingStatus::Confirmed,
        ]);

        $this->get('/')->assertInertia(function ($page) {
            $occupied = $page->toArray()['props']['timeline']['occupied'][0];

            $this->assertSame(
                ['starts_at', 'ends_at', 'duration_minutes', 'service_name'],
                array_keys($occupied),
                'La tira del Home solo puede proyectar geometría y el nombre del servicio.'
            );

            $this->assertArrayNotHasKey('slot_minutes', $page->toArray()['props']['timeline']);
        });
    }
}
