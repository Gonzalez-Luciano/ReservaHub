<?php

namespace App\Enums;

enum CancellationReason: string
{
    case Requested = 'requested';                          // humano: cliente o staff
    case PaymentWindowExpired = 'payment_window_expired';  // sistema
}
