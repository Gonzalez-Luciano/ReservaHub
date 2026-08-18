<?php

namespace App\Services\Payments\Data;

use DateTimeImmutable;

/**
 * `reference` es identidad opaca generada por la aplicación (ULID). No es el id
 * local del pago: la fila local todavía no existe cuando se pide el checkout.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public string $reference,
        public string $amount,
        public string $currency,
        public string $description,
        public string $returnUrl,
        public DateTimeImmutable $expiresAt,
    ) {}
}
