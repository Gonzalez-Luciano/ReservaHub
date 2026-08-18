<?php

namespace App\Actions\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InitiatePayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Booking $booking, User $actingUser): Payment
    {
        return DB::transaction(function () use ($booking) {
            // La fila de la reserva es el límite de serialización: dos
            // iniciaciones simultáneas se ordenan acá, y el índice único parcial
            // de `payments` es la defensa en profundidad.
            $locked = Booking::withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);

            if ($locked->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'booking' => 'Solo una reserva pendiente puede pagar la seña.',
                ]);
            }

            if ($locked->deposit_amount === null || (float) $locked->deposit_amount <= 0) {
                throw ValidationException::withMessages([
                    'booking' => 'Esta reserva no requiere seña.',
                ]);
            }

            if ($locked->payment_expires_at === null || $locked->payment_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'booking' => 'El plazo para pagar la seña de esta reserva venció.',
                ]);
            }

            $live = Payment::withoutGlobalScopes()
                ->where('booking_id', $locked->id)
                ->where('status', PaymentStatus::Pending)
                ->first();

            if ($live !== null) {
                return $live;
            }

            $business = $locked->business;
            $expiresAt = $locked->payment_expires_at->toImmutable();

            // El checkout se pide ANTES del insert para que la fila local nazca
            // con su identidad externa y `external_id` pueda ser NOT NULL.
            $result = $this->gateway->createCheckout(new CheckoutRequest(
                reference: (string) Str::ulid(),
                amount: (string) $locked->deposit_amount,
                currency: $business->currency,
                description: "Seña de reserva #{$locked->id}",
                returnUrl: route('public.bookings.mine.index'),
                expiresAt: $expiresAt,
            ));

            return Payment::create([
                'business_id' => $locked->business_id,
                'booking_id' => $locked->id,
                'provider' => $this->gateway->name(),
                'external_id' => $result->externalId,
                'status' => $result->status,
                'amount' => $locked->deposit_amount,
                'currency' => $business->currency,
                'expires_at' => $expiresAt,
                'last_snapshot' => $result->snapshot,
            ]);
        });
    }
}
