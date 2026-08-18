<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentStatus;
use DateTimeImmutable;

final readonly class CheckoutResult
{
    /**
     * @param  array<string, mixed>  $snapshot  ya redactado
     */
    public function __construct(
        public string $externalId,
        public PaymentStatus $status,
        public DateTimeImmutable $expiresAt,
        public array $snapshot,
    ) {}
}
