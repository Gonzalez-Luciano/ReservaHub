<?php

namespace App\Events;

use App\Enums\CancellationReason;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?User $cancelledBy,
        public readonly CancellationReason $reason = CancellationReason::Requested,
    ) {}
}
