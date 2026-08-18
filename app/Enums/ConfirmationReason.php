<?php

namespace App\Enums;

enum ConfirmationReason: string
{
    case Requested = 'requested';               // humano: staff
    case PaymentApproved = 'payment_approved';  // sistema, tras un pago aprobado
}
