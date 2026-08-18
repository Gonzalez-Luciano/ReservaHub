<?php

namespace App\Services\Payments\Simulated;

use App\Enums\PaymentStatus;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\CheckoutResult;
use App\Services\Payments\Data\ProviderSnapshot;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\Data\WebhookNotification;
use App\Services\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Services\Payments\Exceptions\MalformedWebhookPayloadException;
use App\Services\Payments\Exceptions\UnknownProviderPaymentException;
use App\Services\Payments\WebhookPayloadRedactor;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Proveedor simulado, de primera clase: mantiene su propio estado en
 * `simulated_provider_payments` y NUNCA lee la tabla `payments`. Si la leyera,
 * la reconciliación compararía una fila consigo misma y no probaría nada.
 */
class SimulatedPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds,
        private readonly WebhookPayloadRedactor $redactor = new WebhookPayloadRedactor,
    ) {}

    public function name(): string
    {
        return 'simulated';
    }

    public function createCheckout(CheckoutRequest $request): CheckoutResult
    {
        $externalId = 'sim_pay_'.Str::ulid();

        $payload = [
            'payment_id' => $externalId,
            'status' => PaymentStatus::Pending->value,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'reference' => $request->reference,
        ];

        SimulatedProviderPayment::create([
            'external_id' => $externalId,
            'status' => PaymentStatus::Pending,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'expires_at' => CarbonImmutable::instance($request->expiresAt),
            'payload' => $payload,
        ]);

        return new CheckoutResult(
            externalId: $externalId,
            status: PaymentStatus::Pending,
            expiresAt: $request->expiresAt,
            snapshot: $this->redactor->redact($payload),
        );
    }

    public function checkoutUrl(string $externalId, DateTimeImmutable $expiresAt): string
    {
        return URL::temporarySignedRoute('demo.payments.checkout', $expiresAt, ['externalId' => $externalId]);
    }

    public function parseWebhook(WebhookEnvelope $envelope): WebhookNotification
    {
        $this->assertSignatureIsValid($envelope);

        $payload = json_decode($envelope->rawBody, true);

        if (! is_array($payload)) {
            throw new MalformedWebhookPayloadException('El cuerpo del webhook no es JSON válido.');
        }

        foreach (['event_id', 'payment_id', 'status', 'amount', 'currency', 'occurred_at'] as $key) {
            if (! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                throw new MalformedWebhookPayloadException("Falta el campo `{$key}` en el webhook.");
            }
        }

        $status = PaymentStatus::tryFrom((string) $payload['status']);

        if ($status === null) {
            throw new MalformedWebhookPayloadException('Estado de pago desconocido en el webhook.');
        }

        try {
            $occurredAt = new DateTimeImmutable((string) $payload['occurred_at']);
        } catch (\Exception) {
            throw new MalformedWebhookPayloadException('`occurred_at` no es una fecha válida.');
        }

        return new WebhookNotification(
            eventId: (string) $payload['event_id'],
            externalPaymentId: (string) $payload['payment_id'],
            status: $status,
            amount: (string) $payload['amount'],
            currency: (string) $payload['currency'],
            occurredAt: $occurredAt,
            failureReason: isset($payload['failure_reason']) && is_scalar($payload['failure_reason'])
                ? (string) $payload['failure_reason']
                : null,
            payload: $this->redactor->redact($payload),
        );
    }

    public function fetchPayment(string $externalId): ProviderSnapshot
    {
        $row = SimulatedProviderPayment::where('external_id', $externalId)->first();

        if ($row === null) {
            throw new UnknownProviderPaymentException("El proveedor no conoce {$externalId}.");
        }

        // El proveedor expira por su cuenta: la aplicación no inventa
        // expiraciones locales, las observa.
        if ($row->status === PaymentStatus::Pending && $row->expires_at->isPast()) {
            $row->update(['status' => PaymentStatus::Expired]);
        }

        return new ProviderSnapshot(
            externalId: $row->external_id,
            status: $row->status,
            amount: $row->amount,
            currency: $row->currency,
            occurredAt: $row->approved_at?->toImmutable(),
            failureReason: $row->status === PaymentStatus::Rejected ? 'rejected_by_provider' : null,
            payload: $this->redactor->redact($row->payload),
        );
    }

    /**
     * Mutación del lado del proveedor, disparada por el checkout simulado.
     * Monótona: solo un pago `pending` y no vencido cambia de estado.
     *
     * @return bool `true` si el proveedor aceptó el resultado.
     */
    public function applyOutcome(string $externalId, PaymentStatus $status): bool
    {
        $row = SimulatedProviderPayment::where('external_id', $externalId)->first();

        if ($row === null) {
            throw new UnknownProviderPaymentException("El proveedor no conoce {$externalId}.");
        }

        if ($row->status !== PaymentStatus::Pending || $row->expires_at->isPast()) {
            return false;
        }

        if (! in_array($status, [PaymentStatus::Approved, PaymentStatus::Rejected], true)) {
            return false;
        }

        $payload = $row->payload;
        $payload['status'] = $status->value;

        $row->update([
            'status' => $status,
            'approved_at' => $status === PaymentStatus::Approved ? now() : null,
            'payload' => $payload,
        ]);

        return true;
    }

    /**
     * Payload canónico que el proveedor emite para un evento.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(string $externalId, PaymentStatus $status, string $eventId): array
    {
        $row = SimulatedProviderPayment::where('external_id', $externalId)->first();

        return [
            'event_id' => $eventId,
            'payment_id' => $externalId,
            'status' => $status->value,
            'amount' => $row?->amount ?? '10.00',
            'currency' => $row?->currency ?? 'ARS',
            'occurred_at' => now()->toIso8601String(),
            'reference' => $row?->payload['reference'] ?? null,
            'failure_reason' => $status === PaymentStatus::Rejected ? 'rejected_by_provider' : null,
        ];
    }

    /**
     * Firma de una entrega concreta. `$at` es el momento del INTENTO de entrega,
     * no el del evento: se calcula al entregar, nunca al encolar.
     */
    public function signatureHeaderFor(string $rawBody, ?DateTimeImmutable $at = null): string
    {
        $timestamp = ($at ?? new DateTimeImmutable)->getTimestamp();

        return sprintf('t=%d,v1=%s', $timestamp, $this->sign($rawBody, $timestamp));
    }

    private function assertSignatureIsValid(WebhookEnvelope $envelope): void
    {
        $header = $envelope->header('X-ReservaHub-Signature');

        if ($header === null) {
            throw new InvalidWebhookSignatureException('Falta la firma del webhook.');
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[$key] = $value;
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit((string) $parts['t'])) {
            throw new InvalidWebhookSignatureException('Firma del webhook con formato inválido.');
        }

        $timestamp = (int) $parts['t'];

        if (abs(time() - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidWebhookSignatureException('Firma del webhook fuera de la tolerancia temporal.');
        }

        if (! hash_equals($this->sign($envelope->rawBody, $timestamp), (string) $parts['v1'])) {
            throw new InvalidWebhookSignatureException('Firma del webhook inválida.');
        }
    }

    private function sign(string $rawBody, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
    }
}
