<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\Role;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CancelBooking
{
    /**
     * El motivo es explícito. Un actor nulo NO es por sí solo un permiso para
     * saltarse el plazo de cancelación del cliente: eso lo decide el enum.
     */
    public function handle(
        Booking $booking,
        ?User $actingUser,
        CancellationReason $reason = CancellationReason::Requested,
    ): Booking {
        if ($reason === CancellationReason::Requested && $actingUser === null) {
            throw new InvalidArgumentException('Una cancelación solicitada exige un actor.');
        }

        if ($reason === CancellationReason::PaymentWindowExpired && $actingUser !== null) {
            throw new InvalidArgumentException('La expiración de la ventana de pago es del sistema: no lleva actor.');
        }

        if ($reason === CancellationReason::PaymentWindowExpired && $booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Solo una reserva pendiente expira por falta de pago.',
            ]);
        }

        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => 'Esta reserva no puede cancelarse desde su estado actual.']);
        }

        // El corte de `cancellation_hours` protege al negocio de cancelaciones
        // tardías del CLIENTE. Una expiración del sistema no está sujeta a él:
        // la reserva nunca se pagó y el turno tiene que liberarse.
        if ($reason === CancellationReason::Requested && $actingUser->role === Role::Customer) {
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
            'changed_by' => $actingUser?->id,
            'notes' => $reason === CancellationReason::PaymentWindowExpired
                ? 'Cancelación automática: la seña no se pagó dentro del plazo.'
                : null,
        ]);

        $booking = $booking->fresh();

        event(new BookingCancelled($booking, $actingUser, $reason));

        return $booking;
    }
}
