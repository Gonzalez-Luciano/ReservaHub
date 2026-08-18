<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\ConfirmationReason;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ConfirmBooking
{
    /**
     * El motivo es explícito: un actor nulo NO significa por sí solo
     * "confirmada por un pago". Sin el enum, el significado de negocio quedaría
     * implícito en la ausencia de un argumento.
     */
    public function handle(
        Booking $booking,
        ?User $actingUser,
        ConfirmationReason $reason = ConfirmationReason::Requested,
        ?Payment $payment = null,
    ): Booking {
        if ($reason === ConfirmationReason::Requested && ($actingUser === null || $payment !== null)) {
            throw new InvalidArgumentException('Una confirmación manual exige un actor y no lleva pago asociado.');
        }

        if ($reason === ConfirmationReason::PaymentApproved && ($actingUser !== null || $payment === null)) {
            throw new InvalidArgumentException('Una confirmación por pago aprobado es del sistema y exige el pago.');
        }

        if ($booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Solo una reserva pendiente puede confirmarse.']);
        }

        $booking->update(['status' => BookingStatus::Confirmed]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Confirmed,
            'changed_by' => $actingUser?->id,
            'notes' => $reason === ConfirmationReason::PaymentApproved
                ? "Confirmada por pago aprobado #{$payment->id}."
                : null,
        ]);

        $booking = $booking->fresh();

        event(new BookingConfirmed($booking));

        return $booking;
    }
}
