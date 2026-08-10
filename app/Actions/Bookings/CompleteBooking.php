<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CompleteBooking
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => 'Solo una reserva confirmada puede completarse.']);
        }

        $booking->update(['status' => BookingStatus::Completed]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Confirmed,
            'to_status' => BookingStatus::Completed,
            'changed_by' => $actingUser->id,
        ]);

        return $booking->fresh();
    }
}
