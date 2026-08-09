<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CreateBooking;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateBookingTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    private function setUpBusinessWithSchedule(?float $depositAmount = null): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create([
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 50,
            'deposit_amount' => $depositAmount,
        ]);
        $customer = User::factory()->customer()->create();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        app()->instance(Business::class, $business);

        return compact('business', 'employee', 'service', 'customer');
    }

    public function test_creates_a_confirmed_booking_when_service_has_no_deposit(): void
    {
        // Fake only BookingCreated: a blanket Event::fake() also replaces
        // Eloquent's model event dispatcher, which would silently stop the
        // tenant-scoped models' `creating` hook (BelongsToBusiness) from
        // auto-filling business_id.
        Event::fake(BookingCreated::class);
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $slot = $this->nextMonday()->setTime(9, 0);

        $booking = app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('09:00', $booking->starts_at->format('H:i'));
        $this->assertSame('09:30', $booking->ends_at->format('H:i'));
        $this->assertSame('50.00', $booking->price);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'from_status' => null,
            'to_status' => 'confirmed',
            'changed_by' => $customer->id,
        ]);
        Event::assertDispatched(BookingCreated::class, fn (BookingCreated $event) => $event->booking->is($booking));
    }

    public function test_creates_a_pending_booking_when_service_requires_a_deposit(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule(depositAmount: 10);
        $slot = $this->nextMonday()->setTime(9, 0);

        $booking = app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);

        $this->assertSame(BookingStatus::Pending, $booking->status);
    }

    public function test_rejects_a_slot_already_taken_by_another_booking(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $slot = $this->nextMonday()->setTime(9, 0);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $slot,
            'ends_at' => $slot->addMinutes(30),
        ]);

        $this->expectException(ValidationException::class);

        app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);
    }

    public function test_rejects_a_slot_outside_working_hours(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $outsideHours = $this->nextMonday()->setTime(20, 0);

        $this->expectException(ValidationException::class);

        app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $outsideHours->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);
    }
}
