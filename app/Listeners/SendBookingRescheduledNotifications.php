<?php

namespace App\Listeners;

use App\Enums\NotificationAudience;
use App\Events\BookingRescheduled;
use App\Notifications\Bookings\BookingRescheduledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingRescheduledNotifications implements ShouldQueue
{
    public function handle(BookingRescheduled $event): void
    {
        $booking = $event->booking;
        $previous = $event->previousStartsAt;

        $booking->customer->notify(new BookingRescheduledNotification($booking, $previous, NotificationAudience::Customer));
        $booking->employee->notify(new BookingRescheduledNotification($booking, $previous, NotificationAudience::Employee));
    }
}
