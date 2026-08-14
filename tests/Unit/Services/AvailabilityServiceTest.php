<?php

namespace Tests\Unit\Services;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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

    public function test_folds_multiple_breaks_including_one_touching_the_window_start(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        $schedule = Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);
        // First break starts exactly at the window start (boundary-touching),
        // the second one splits the remainder in two.
        $schedule->breaks()->create(['start_time' => '09:00', 'end_time' => '09:30']);
        $schedule->breaks()->create(['start_time' => '10:30', 'end_time' => '11:00']);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $this->nextMonday());

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:30', '10:00', '11:00', '11:30'], $starts);
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
        // The requested service and the already-booked service carry *different*
        // buffers on purpose: an existing booking's busy span must use its own
        // service's buffer (40'), while a candidate's occupied span must use the
        // requested service's buffer (10'). Swapping the two would yield [].
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 10]);
        $bookedService = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 40]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        // No explicit status: the factory default is Pending, which must block too.
        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $bookedService->id,
            'starts_at' => $date->setTime(10, 0),
            'ends_at' => $date->setTime(10, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00'], $starts);
    }

    public function test_completed_booking_blocks_its_slot(): void
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

        Booking::factory()->completed()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:30'], $starts);
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

        CarbonImmutable::setTestNow($date->addYear());

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(2, $slots);
    }

    public function test_computes_correct_local_slots_when_business_timezone_crosses_utc_midnight(): void
    {
        // Asia/Tokyo is UTC+9: a 00:00-02:00 local schedule falls on the
        // *previous* UTC calendar day (15:00-17:00 UTC).
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = CarbonImmutable::parse('next monday', 'Asia/Tokyo')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '00:00',
            'end_time' => '02:00',
            'is_active' => true,
        ]);

        TimeOff::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->setTime(0, 30)->utc(),
            'ends_at' => $date->setTime(1, 0)->utc(),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(3, $slots);
        $this->assertSame('00:00', $slots[0]['starts_at']->format('H:i'));
        $this->assertSame('Asia/Tokyo', $slots[0]['starts_at']->getTimezone()->getName());
        $this->assertSame('01:00', $slots[1]['starts_at']->format('H:i'));
        $this->assertSame('01:30', $slots[2]['starts_at']->format('H:i'));
    }

    public function test_treats_the_dates_calendar_day_as_business_local_regardless_of_the_dates_own_timezone(): void
    {
        // America/Argentina/Buenos_Aires is UTC-3. A naive caller (the app runs on
        // UTC) builds "next monday" as UTC midnight; converting that instant into
        // the business timezone would land on *Sunday* 21:00 and compute the wrong
        // day. The date's Y-m-d is the business-local calendar day being queried.
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('Y-m-d H:i'), $slots);
        $this->assertSame([
            $date->format('Y-m-d').' 09:00',
            $date->format('Y-m-d').' 09:30',
        ], $starts);
        $this->assertSame('America/Argentina/Buenos_Aires', $slots[0]['starts_at']->getTimezone()->getName());
    }

    public function test_booking_starting_at_the_window_end_still_blocks_via_the_requested_services_buffer(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        // Requested service: 30' duration + 15' buffer. Window 09:00-10:00 yields
        // candidates 09:00 and 09:30; 09:30's occupied span runs to 10:15.
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
        $bookedService = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        // Starts exactly at the window end, so a `starts_at < windowEnd` bound
        // never fetches it — yet [10:00, 10:30] overlaps the 09:30 candidate's
        // buffer-extended span [09:30, 10:15].
        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $bookedService->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(10, 0),
            'ends_at' => $date->setTime(10, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00'], $starts);
    }

    public function test_throws_when_the_service_belongs_to_another_business(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $otherBusiness = Business::factory()->create();
        $foreignService = Service::factory()->for($otherBusiness)->create();

        $this->expectException(InvalidArgumentException::class);

        $this->service->getAvailableSlots($business, $foreignService, $employee, $this->nextMonday());
    }

    public function test_throws_when_the_employee_belongs_to_another_business(): void
    {
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create();
        $otherBusiness = Business::factory()->create();
        $foreignEmployee = User::factory()->employee()->create(['business_id' => $otherBusiness->id]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->getAvailableSlots($business, $service, $foreignEmployee, $this->nextMonday());
    }

    public function test_excludes_a_given_booking_id_from_the_busy_span_calculation(): void
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

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $withoutExclusion = $this->service->getAvailableSlots($business, $service, $employee, $date);
        $withExclusion = $this->service->getAvailableSlots($business, $service, $employee, $date, excludeBookingId: $booking->id);

        $this->assertSame(['09:30'], array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $withoutExclusion));
        $this->assertSame(['09:00', '09:30'], array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $withExclusion));
    }

    public function test_an_inactive_employee_has_no_slots(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create([
            'business_id' => $business->id,
            'is_active' => false,
        ]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots(
            $business,
            $service,
            $employee,
            CarbonImmutable::parse('next monday', 'UTC')->startOfDay(),
        );

        $this->assertSame([], $slots);
    }

    public function test_a_day_inside_a_business_holiday_has_no_slots(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => $monday->toDateString(),
            'ends_on' => $monday->addDays(2)->toDateString(),
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots($business, $service, $employee, $monday);

        $this->assertSame([], $slots);
    }

    public function test_the_day_before_a_holiday_still_has_slots(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => $monday->addDay()->toDateString(),
            'ends_on' => $monday->addDay()->toDateString(),
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots($business, $service, $employee, $monday);

        $this->assertNotEmpty($slots);
    }

    public function test_a_holiday_from_another_business_does_not_affect_availability(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        BusinessHoliday::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'starts_on' => $monday->toDateString(),
            'ends_on' => $monday->toDateString(),
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots($business, $service, $employee, $monday);

        $this->assertNotEmpty($slots);
    }
}
