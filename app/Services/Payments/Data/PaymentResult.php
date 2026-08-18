<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentStatus;
use DateTimeImmutable;

/**
 * Entrada única de ApplyPaymentResult: el webhook y la reconciliación producen
 * este mismo tipo, así que ninguna de las dos rutas tiene lógica propia.
 */
final readonly class PaymentResult
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public PaymentStatus $status,
        public string $amount,
        public string $currency,
        public ?DateTimeImmutable $occurredAt,
        public array $snapshot,
        public ?string $failureReason,
    ) {}

    public static function fromWebhook(WebhookNotification $notification): self
    {
        return new self(
            $notification->status,
            $notification->amount,
            $notification->currency,
            $notification->occurredAt,
            $notification->payload,
            $notification->failureReason,
        );
    }

    public static function fromSnapshot(ProviderSnapshot $snapshot): self
    {
        return new self(
            $snapshot->status,
            $snapshot->amount,
            $snapshot->currency,
            $snapshot->occurredAt,
            $snapshot->payload,
            $snapshot->failureReason,
        );
    }
}
