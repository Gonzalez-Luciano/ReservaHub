<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\ProcessPaymentWebhook;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Entrega del proveedor simulado: asincrónica y con reintentos, como la de un
 * proveedor real, pero **en proceso**. No hace HTTP: construye el mismo
 * WebhookEnvelope que construiría el controller y entra al mismo borde. Así la
 * demo no depende de DNS, Cloudflare, túneles ni de APP_URL.
 */
class DeliverSimulatedProviderWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(
        public readonly string $externalId,
        public readonly PaymentStatus $status,
        public readonly string $eventId,
    ) {
        $this->afterCommit = true;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(PaymentGateway $gateway, ProcessPaymentWebhook $processor): void
    {
        /** @var SimulatedPaymentGateway $gateway */
        $payload = $gateway->payloadFor($this->externalId, $this->status, $this->eventId);
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        // La marca temporal `t` y el HMAC se calculan AHORA, en el intento de
        // entrega: firmarlos al encolar haría que un retraso de cola mayor que
        // la tolerancia invalidara la propia entrega legítima del proveedor.
        $envelope = new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
            'Content-Type' => 'application/json',
        ]);

        $result = $processor->handle($envelope);

        if ($result->status === WebhookProcessingStatus::Failed) {
            // Lanzar es lo que hace que `tries`/`backoff` reintenten de verdad;
            // devolver en silencio perdería la entrega. El `eventId` se conserva
            // en el reintento: mismo evento lógico, firma nueva.
            throw new RuntimeException("Entrega simulada fallida para {$this->externalId}: {$result->reason}.");
        }
    }
}
