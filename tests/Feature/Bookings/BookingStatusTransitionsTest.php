<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CompleteBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\MarkNoShow;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingStatusTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private function staffFor(Business $business): User
    {
        return User::factory()->employee()->create(['business_id' => $business->id]);
    }

    public function test_confirm_moves_pending_to_confirmed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $confirmed = app(ConfirmBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Confirmed, $confirmed->status);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
        ]);
    }

    public function test_confirm_rejects_a_booking_that_is_not_pending(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        $this->expectException(ValidationException::class);

        app(ConfirmBooking::class)->handle($booking, $staff);
    }

    public function test_complete_moves_confirmed_to_completed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        $completed = app(CompleteBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Completed, $completed->status);
    }

    public function test_complete_rejects_a_booking_that_is_not_confirmed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $this->expectException(ValidationException::class);

        app(CompleteBooking::class)->handle($booking, $staff);
    }

    public function test_mark_no_show_moves_confirmed_to_no_show(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        $noShow = app(MarkNoShow::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::NoShow, $noShow->status);
    }

    public function test_mark_no_show_rejects_a_booking_that_is_not_confirmed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $this->expectException(ValidationException::class);

        app(MarkNoShow::class)->handle($booking, $staff);
    }
}
