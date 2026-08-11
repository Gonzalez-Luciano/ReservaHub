<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CancelBooking
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => 'Esta reserva no puede cancelarse desde su estado actual.']);
        }

        if ($actingUser->role === Role::Customer) {
            $business = $booking->business;
            $cutoff = CarbonImmutable::parse($booking->starts_at)->subHours($business->cancellation_hours);

            if (CarbonImmutable::now($business->timezone)->greaterThan($cutoff)) {
                throw ValidationException::withMessages([
                    'starts_at' => "No podés cancelar esta reserva; faltan menos de {$business->cancellation_hours} horas para el turno.",
                ]);
            }
        }

        $fromStatus = $booking->status;
        $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => $fromStatus,
            'to_status' => BookingStatus::Cancelled,
            'changed_by' => $actingUser->id,
        ]);

        $booking = $booking->fresh();

        event(new BookingCancelled($booking, $actingUser));

        return $booking;
    }
}
