<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Notifications\Bookings\BookingConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingConfirmedNotifications implements ShouldQueue
{
    public int $tries = 3;

    /**
     * La confirmación por pago ocurre dentro de la transacción de
     * ApplyPaymentResult: sin esto, un rollback dejaría el email ya encolado.
     */
    public bool $afterCommit = true;

    public function handle(BookingConfirmed $event): void
    {
        $event->booking->customer->notify(new BookingConfirmedNotification($event->booking));
    }
}
