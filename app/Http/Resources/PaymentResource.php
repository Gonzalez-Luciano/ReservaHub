<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatus;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'application_outcome' => $this->application_outcome?->value,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            // Solo para un intento vivo y con ventana vigente: una URL de
            // checkout hacia un intento muerto induce a error.
            'checkout_url' => $this->checkoutUrl(),
        ];
    }

    private function checkoutUrl(): ?string
    {
        if ($this->status !== PaymentStatus::Pending) {
            return null;
        }

        if ($this->expires_at === null || $this->expires_at->isPast()) {
            return null;
        }

        $bookingWindow = $this->booking?->payment_expires_at;

        if ($bookingWindow === null || $bookingWindow->isPast()) {
            return null;
        }

        return app(PaymentGateway::class)->checkoutUrl($this->external_id, $this->expires_at->toImmutable());
    }
}
