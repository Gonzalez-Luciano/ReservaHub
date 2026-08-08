<?php

namespace Tests\Unit\Services;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AvailabilityService;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_returns_empty_array_when_no_active_schedule_for_that_day(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $this->nextMonday());

        $this->assertSame([], $slots);
    }

    public function test_returns_slots_every_duration_minutes_across_the_working_window(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(2, $slots);
        $this->assertSame('09:00', $slots[0]['starts_at']->format('H:i'));
        $this->assertSame('09:30', $slots[0]['ends_at']->format('H:i'));
        $this->assertSame('09:30', $slots[1]['starts_at']->format('H:i'));
        $this->assertSame('10:00', $slots[1]['ends_at']->format('H:i'));
    }

    public function test_inactive_schedule_yields_no_slots(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => false,
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $this->nextMonday());

        $this->assertSame([], $slots);
    }

    public function test_excludes_slots_overlapping_a_schedule_break(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        $schedule = Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);
        $schedule->breaks()->create(['start_time' => '10:00', 'end_time' => '10:30']);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $this->nextMonday());

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00', '09:30', '10:30'], $starts);
    }

    public function test_excludes_slots_during_a_full_day_time_off(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        TimeOff::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->subDay(),
            'ends_at' => $date->addDay(),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertSame([], $slots);
    }

    public function test_excludes_slots_during_a_partial_day_time_off(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        TimeOff::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00', '10:30'], $starts);
    }

    public function test_existing_booking_blocks_its_slot_and_the_next_one_via_buffer(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 10]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(10, 0),
            'ends_at' => $date->setTime(10, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00'], $starts);
    }

    public function test_cancelled_and_no_show_bookings_do_not_block_slots(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        Booking::factory()->cancelled()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);
        Booking::factory()->noShow()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 0),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(2, $slots);
    }

    public function test_zero_buffer_allows_back_to_back_slots(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:30'], $starts);
    }

    public function test_booking_ending_before_window_still_blocks_via_buffer_reaching_into_window(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        // Booking ends at 08:50, before the 09:00 window start, but its 15-minute
        // buffer pushes its occupied end to 09:05 — reaching into the window.
        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(8, 20),
            'ends_at' => $date->setTime(8, 50),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertNotContains('09:00', $starts);
    }

    public function test_excludes_past_slots_when_date_is_today(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::from($today->dayOfWeek),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        CarbonImmutable::setTestNow($today->setTime(9, 45));

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $today);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['10:00', '10:30'], $starts);
    }

    public function test_does_not_filter_by_current_time_for_a_future_date(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        CarbonImmutable::setTestNow($date->subDay());

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(2, $slots);
    }
}
