<?php

namespace App\Actions\Payments;

use App\Actions\Bookings\ConfirmBooking;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationReason;
use App\Enums\PaymentApplicationOutcome;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\Data\PaymentApplicationResult;
use App\Services\Payments\Data\PaymentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Camino ÚNICO de aplicación de un resultado del proveedor: lo usan por igual
 * el borde de webhook y la reconciliación. Ningún otro lugar muta el estado del
 * pago ni confirma la reserva.
 *
 * Orden de bloqueo global: bookings → payments (el evento de webhook, cuando
 * existe, ya quedó bloqueado antes por ProcessPaymentWebhook).
 */
class ApplyPaymentResult
{
    public function __construct(private readonly ConfirmBooking $confirmBooking) {}

    public function handle(Payment $payment, PaymentResult $result): PaymentApplicationResult
    {
        return DB::transaction(function () use ($payment, $result) {
            $booking = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->booking_id);
            $locked = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);

            if (! $locked->status->canTransitionTo($result->status)) {
                Log::warning('payments.result_ignored', [
                    'payment_id' => $locked->id,
                    'local_status' => $locked->status->value,
                    'provider_status' => $result->status->value,
                ]);

                return new PaymentApplicationResult(
                    accepted: false,
                    outcome: PaymentApplicationOutcome::NoAction,
                    reasonCode: 'payment_already_terminal',
                );
            }

            // El proveedor sigue pendiente: observación válida, no transición.
            if ($result->status === PaymentStatus::Pending) {
                $locked->update(['last_snapshot' => $result->snapshot]);

                return new PaymentApplicationResult(
                    accepted: true,
                    outcome: PaymentApplicationOutcome::NoAction,
                    reasonCode: 'provider_still_pending',
                );
            }

            // La fila local es la autoridad sobre el dinero (spec §17): un
            // resultado terminal (approved/rejected/expired) que no coincide
            // en monto o moneda con lo que se registró al iniciar el pago se
            // ignora sin mutar nada. Vive aquí, no en el borde de webhook,
            // porque `payments:reconcile` llega al mismo `handle()` con un
            // snapshot del proveedor y necesita la misma garantía.
            if ($mismatch = $this->mismatchReason($locked, $result)) {
                Log::warning('payments.result_mismatch', [
                    'payment_id' => $locked->id,
                    'expected_amount' => $locked->amount,
                    'expected_currency' => $locked->currency,
                    'incoming_amount' => $result->amount,
                    'incoming_currency' => $result->currency,
                ]);

                return new PaymentApplicationResult(
                    accepted: false,
                    outcome: PaymentApplicationOutcome::NoAction,
                    reasonCode: $mismatch,
                );
            }

            if ($result->status !== PaymentStatus::Approved) {
                $locked->update([
                    'status' => $result->status,
                    'failure_reason' => $result->failureReason,
                    'last_snapshot' => $result->snapshot,
                    'application_outcome' => PaymentApplicationOutcome::NoAction,
                ]);

                return new PaymentApplicationResult(
                    accepted: true,
                    outcome: PaymentApplicationOutcome::NoAction,
                    reasonCode: $result->status->value,
                );
            }

            $bookingIsPending = $booking->status === BookingStatus::Pending;

            $locked->update([
                'status' => PaymentStatus::Approved,
                'paid_at' => $result->occurredAt ?? now(),
                'last_snapshot' => $result->snapshot,
                'applied_at' => $bookingIsPending ? now() : null,
                'application_outcome' => $bookingIsPending
                    ? PaymentApplicationOutcome::BookingConfirmed
                    : PaymentApplicationOutcome::BookingNotPending,
            ]);

            if (! $bookingIsPending) {
                // El dinero existe y se registra, pero una reserva que ya no
                // está pendiente NUNCA se resucita.
                Log::warning('payments.approved_without_application', [
                    'payment_id' => $locked->id,
                    'booking_id' => $booking->id,
                    'booking_status' => $booking->status->value,
                ]);

                return new PaymentApplicationResult(
                    accepted: true,
                    outcome: PaymentApplicationOutcome::BookingNotPending,
                    reasonCode: 'booking_not_pending',
                );
            }

            // Se pasa `$locked` (la instancia releída bajo el lock de fila),
            // nunca el `$payment` recibido como parámetro: `$payment` puede
            // estar obsoleto frente a lo que otra transacción escribió justo
            // antes de que este `lockForUpdate()` se resolviera. No
            // "simplificar" esto a `$payment` — reintroduciría una lectura
            // obsoleta bajo el propio lock que existe para evitarla.
            $this->confirmBooking->handle($booking, null, ConfirmationReason::PaymentApproved, $locked);

            return new PaymentApplicationResult(
                accepted: true,
                outcome: PaymentApplicationOutcome::BookingConfirmed,
                reasonCode: 'booking_confirmed',
            );
        });
    }

    private function mismatchReason(Payment $payment, PaymentResult $result): ?string
    {
        if (bccomp((string) $payment->amount, $result->amount, 2) !== 0) {
            return 'amount_mismatch';
        }

        if (strtoupper($payment->currency) !== strtoupper($result->currency)) {
            return 'currency_mismatch';
        }

        return null;
    }
}
