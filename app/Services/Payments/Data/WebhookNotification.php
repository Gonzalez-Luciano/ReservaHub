<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentStatus;
use DateTimeImmutable;

final readonly class WebhookNotification
{
    /**
     * @param  array<string, mixed>  $payload  ya redactado
     */
    public function __construct(
        public string $eventId,
        public string $externalPaymentId,
        public PaymentStatus $status,
        public string $amount,
        public string $currency,
        public DateTimeImmutable $occurredAt,
        public ?string $failureReason,
        public array $payload,
    ) {}
}
