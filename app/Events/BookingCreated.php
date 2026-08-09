<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCreated
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
