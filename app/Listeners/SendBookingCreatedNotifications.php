<?php

namespace App\Listeners;

use App\Enums\NotificationAudience;
use App\Events\BookingCreated;
use App\Notifications\Bookings\BookingCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingCreatedNotifications implements ShouldQueue
{
    public function handle(BookingCreated $event): void
    {
        $booking = $event->booking;

        $booking->customer->notify(new BookingCreatedNotification($booking, NotificationAudience::Customer));
        $booking->employee->notify(new BookingCreatedNotification($booking, NotificationAudience::Employee));
    }
}
