<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Notifications\Bookings\BookingConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingConfirmedNotifications implements ShouldQueue
{
    public int $tries = 3;

    public function handle(BookingConfirmed $event): void
    {
        $event->booking->customer->notify(new BookingConfirmedNotification($event->booking));
    }
}
