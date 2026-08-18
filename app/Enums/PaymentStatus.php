<?php

namespace App\Enums;

/**
 * Estados internos del pago, deliberadamente monótonos: `pending` es el único
 * estado no terminal, y ningún estado terminal vuelve atrás ni salta a otro.
 * `pending → pending` es legal a propósito: es la observación de que el
 * proveedor sigue pendiente, no una transición.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === self::Pending;
    }
}
