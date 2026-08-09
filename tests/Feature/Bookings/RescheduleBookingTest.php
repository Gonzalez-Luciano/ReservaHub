<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RescheduleBookingTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_moves_the_booking_to_a_new_available_slot(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
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
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $rescheduled = app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);

        $this->assertSame('09:30', $rescheduled->starts_at->format('H:i'));
        $this->assertSame('10:00', $rescheduled->ends_at->format('H:i'));
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'from_status' => 'confirmed',
            'to_status' => 'confirmed',
        ]);
    }

    public function test_no_op_reschedule_to_its_own_current_slot_succeeds(): void
    {
        // Without excluding the booking's own row from the busy-span check, this would
        // always fail: the booking currently occupies exactly the slot it's "moving" to.
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
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
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 0),
        ]);

        $rescheduled = app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);

        $this->assertSame('09:30', $rescheduled->starts_at->format('H:i'));
    }

    public function test_rejects_reschedule_to_an_occupied_slot(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
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
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);
        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 0),
        ]);

        $this->expectException(ValidationException::class);

        app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);
    }

    public function test_rejects_rescheduling_a_cancelled_booking(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        $booking = Booking::factory()->cancelled()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $this->expectException(ValidationException::class);

        app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);
    }

    public function test_history_note_renders_both_times_in_the_business_timezone(): void
    {
        // Asia/Tokyo is UTC+9. Eloquent's `starts_at` cast returns in the app's
        // UTC timezone unless explicitly converted — if the old time in the note
        // isn't converted to the business timezone (like the new time is), the
        // note mixes a UTC old-time with a Tokyo-local new-time.
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = CarbonImmutable::parse('next monday', 'Asia/Tokyo')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        // Stored explicitly in UTC, matching this codebase's convention for
        // persisting business-local instants in tests (see
        // AvailabilityServiceTest's Tokyo test) — Eloquent's 'datetime' cast
        // formats a Carbon value's local wall-clock into the stored string as-is,
        // so a Tokyo-local Carbon passed directly would be misread back as UTC.
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0)->utc(),
            'ends_at' => $date->setTime(9, 30)->utc(),
        ]);

        app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);

        $expectedOld = $date->setTime(9, 0)->format('Y-m-d H:i');
        $expectedNew = $date->setTime(9, 30)->format('Y-m-d H:i');

        $history = BookingStatusHistory::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->assertStringContainsString($expectedOld, $history->notes);
        $this->assertStringContainsString($expectedNew, $history->notes);
    }

    public function test_rejects_rescheduling_to_a_slot_in_the_past(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
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
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $pastSlot = $date->subWeeks(2)->setTime(9, 0);

        $this->expectException(ValidationException::class);

        app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $pastSlot->toIso8601String(),
        ], $customer);
    }
}
