<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Events\BookingNoShow;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MarkNoShow
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => 'Solo una reserva confirmada puede marcarse como ausencia.']);
        }

        $booking->update(['status' => BookingStatus::NoShow]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Confirmed,
            'to_status' => BookingStatus::NoShow,
            'changed_by' => $actingUser->id,
        ]);

        $booking = $booking->fresh();

        event(new BookingNoShow($booking));

        return $booking;
    }
}
