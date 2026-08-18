<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentApplicationOutcome;

/**
 * `accepted` distingue un `no_action` legítimo (un rechazo o una expiración
 * aplicados con éxito) de un no-op por transición ilegal. Sin él, el borde de
 * webhook no podría elegir `processed` vs `ignored`.
 */
final readonly class PaymentApplicationResult
{
    public function __construct(
        public bool $accepted,
        public PaymentApplicationOutcome $outcome,
        public string $reasonCode,
    ) {}
}
