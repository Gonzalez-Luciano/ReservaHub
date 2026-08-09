<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CancelBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CancelBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_staff_can_cancel_a_pending_booking(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24]);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $cancelled = app(CancelBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'to_status' => 'cancelled',
            'changed_by' => $staff->id,
        ]);
    }

    public function test_customer_can_cancel_within_the_cancellation_window(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(3)->addMinutes(30),
        ]);

        $cancelled = app(CancelBooking::class)->handle($booking, $customer);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
    }

    public function test_customer_cannot_cancel_inside_the_cancellation_window(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addHours(2),
            'ends_at' => CarbonImmutable::now('UTC')->addHours(2)->addMinutes(30),
        ]);

        $this->expectException(ValidationException::class);

        app(CancelBooking::class)->handle($booking, $customer);
    }

    public function test_staff_can_cancel_inside_the_cancellation_window(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addHours(2),
            'ends_at' => CarbonImmutable::now('UTC')->addHours(2)->addMinutes(30),
        ]);

        $cancelled = app(CancelBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
    }

    public function test_rejects_cancelling_an_already_completed_booking(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->completed()->create(['business_id' => $business->id]);

        $this->expectException(ValidationException::class);

        app(CancelBooking::class)->handle($booking, $staff);
    }
}
