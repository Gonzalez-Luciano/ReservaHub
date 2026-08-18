<?php

namespace App\Support;

use App\Enums\BookingStatus;
use Illuminate\Support\Facades\DB;

/**
 * Rellena la ventana de pago de las reservas anteriores a la Fase 9.
 *
 * Solo alcanza a reservas `pending` con seña **cuyo turno sigue en el futuro**:
 * son las únicas que pueden recibir una ventana honesta. Rellenar una reserva
 * cuyo turno ya empezó produciría un valor ya vencido y la cancelaría en el
 * primer barrido de `bookings:expire-unpaid` — una cancelación masiva como
 * efecto colateral de desplegar. Esas quedan en `null` (semántica legacy: no se
 * pueden pagar y no se cancelan solas) y las limpia el staff a mano.
 */
class PaymentWindowBackfill
{
    /** @return int cantidad de reservas actualizadas */
    public function run(): int
    {
        $now = now();
        $freshDeadline = $now->copy()->addMinutes((int) config('payments.window_minutes', 30));

        return DB::update(
            'update bookings
                set payment_expires_at = least(?::timestamp, starts_at)
              where status = ?
                and deposit_amount is not null
                and deposit_amount > 0
                and payment_expires_at is null
                and starts_at > ?::timestamp',
            [
                $freshDeadline->toDateTimeString(),
                BookingStatus::Pending->value,
                $now->toDateTimeString(),
            ],
        );
    }
}
