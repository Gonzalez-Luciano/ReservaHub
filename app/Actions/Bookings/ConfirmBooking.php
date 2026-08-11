<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConfirmBooking
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Solo una reserva pendiente puede confirmarse.']);
        }

        $booking->update(['status' => BookingStatus::Confirmed]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Confirmed,
            'changed_by' => $actingUser->id,
        ]);

        $booking = $booking->fresh();

        event(new BookingConfirmed($booking));

        return $booking;
    }
}
