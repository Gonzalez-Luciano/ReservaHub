<?php

namespace App\Listeners;

use App\Enums\NotificationAudience;
use App\Events\BookingCancelled;
use App\Notifications\Bookings\BookingCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingCancelledNotifications implements ShouldQueue
{
    public int $tries = 3;

    /**
     * `bookings:expire-unpaid` cancela dentro de una transacción: sin esto, un
     * rollback dejaría los emails ya encolados.
     */
    public bool $afterCommit = true;

    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking;
        $by = $event->cancelledBy;

        $booking->customer->notify(new BookingCancelledNotification($booking, $by, NotificationAudience::Customer, $event->reason));
        $booking->employee->notify(new BookingCancelledNotification($booking, $by, NotificationAudience::Employee, $event->reason));
    }
}
