<?php

namespace App\Enums;

enum PaymentApplicationOutcome: string
{
    case BookingConfirmed = 'booking_confirmed';
    case BookingNotPending = 'booking_not_pending';
    case NoAction = 'no_action';
}
