<?php

namespace App\Listeners;

use App\Enums\NotificationAudience;
use App\Events\BookingCancelled;
use App\Notifications\Bookings\BookingCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingCancelledNotifications implements ShouldQueue
{
    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking;
        $by = $event->cancelledBy;

        $booking->customer->notify(new BookingCancelledNotification($booking, $by, NotificationAudience::Customer));
        $booking->employee->notify(new BookingCancelledNotification($booking, $by, NotificationAudience::Employee));
    }
}
