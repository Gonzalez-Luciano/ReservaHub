<?php

namespace App\Listeners;

use App\Enums\BookingChange;
use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;
use App\Events\Broadcasting\BookingChanged;

/**
 * Única frontera dominio → transporte. Los eventos de reserva siguen siendo
 * PHP plano y no saben que existe el broadcasting.
 *
 * NO es ShouldQueue: corre en proceso, no hace I/O y solo construye y despacha
 * un objeto. Puede correr dentro de una transacción sin riesgo, porque lo que
 * se difiere al commit es BookingChanged (ShouldDispatchAfterCommit), no él.
 *
 * El tipo unión es lo que registra este listener para los seis eventos sin un
 * Event::listen manual: DiscoverEvents lee los parámetros con
 * Reflector::getParameterClassNames(), que devuelve todos los miembros de la
 * unión.
 */
class BroadcastBookingChange
{
    public function handle(
        BookingCreated|BookingConfirmed|BookingCancelled|
        BookingRescheduled|BookingCompleted|BookingNoShow $event
    ): void {
        $booking = $event->booking;

        event(new BookingChanged(
            businessId: $booking->business_id,
            bookingId: $booking->id,
            change: BookingChange::forEvent($event),
            updatedAt: $booking->updated_at->toIso8601String(),
        ));
    }
}
