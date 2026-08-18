<?php

namespace App\Services\Payments;

use App\Actions\Payments\ApplyPaymentResult;
use App\Enums\WebhookEventStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentResult;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\Data\WebhookNotification;
use App\Services\Payments\Data\WebhookProcessingResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Borde ÚNICO de procesamiento: lo usan el endpoint HTTP y la entrega simulada
 * interna. Garantiza dos invariantes a la vez:
 *
 * 1. el mismo evento externo nunca produce el efecto dos veces
 *    (unicidad en base + lock de fila + re-lectura del estado);
 * 2. un fallo transitorio no vuelve el evento imposible de procesar
 *    (el evento queda `failed`/`received`, ambos reprocesables).
 *
 * Reclamar-y-olvidar con `insertOrIgnore` cumpliría (1) y rompería (2): si el
 * proceso muere entre el insert y el efecto, el reintento del proveedor vería
 * "duplicado" y el evento quedaría muerto para siempre.
 *
 * Orden de bloqueo global: webhook_events → bookings → payments.
 */
class ProcessPaymentWebhook
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ApplyPaymentResult $applyPaymentResult,
    ) {}

    public function handle(WebhookEnvelope $envelope): WebhookProcessingResult
    {
        // 1. Firma y forma. Lo que no verifica no se persiste.
        $notification = $this->gateway->parseWebhook($envelope);

        // 2. Identidad, en su propia transacción.
        $this->claimIdentity($notification);

        // 3. Proceso, en una única transacción.
        try {
            return DB::transaction(function () use ($notification) {
                $event = WebhookEvent::where('provider', $this->gateway->name())
                    ->where('external_event_id', $notification->eventId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $event->status->isReprocessable()) {
                    // Entrega repetida: la fila original conserva su resultado.
                    return new WebhookProcessingResult(WebhookProcessingStatus::Duplicate, $event->outcome_reason);
                }

                $payment = Payment::withoutGlobalScopes()
                    ->where('provider', $this->gateway->name())
                    ->where('external_id', $notification->externalPaymentId)
                    ->first();

                if ($payment === null) {
                    // Anómalo: la iniciación persiste el `external_id` antes de
                    // que exista entrega alguna. Reintentable a propósito.
                    $this->finish($event, WebhookEventStatus::Failed, 'unknown_payment', incrementAttempts: true);

                    Log::warning('payments.webhook_unknown_payment', [
                        'provider' => $this->gateway->name(),
                        'event_id' => $notification->eventId,
                    ]);

                    return new WebhookProcessingResult(WebhookProcessingStatus::Failed, 'unknown_payment');
                }

                if ($mismatch = $this->mismatchReason($payment, $notification)) {
                    $this->finish($event, WebhookEventStatus::Ignored, $mismatch);

                    Log::warning('payments.webhook_amount_mismatch', [
                        'payment_id' => $payment->id,
                        'expected_amount' => $payment->amount,
                        'expected_currency' => $payment->currency,
                        'incoming_amount' => $notification->amount,
                        'incoming_currency' => $notification->currency,
                    ]);

                    return new WebhookProcessingResult(WebhookProcessingStatus::Ignored, $mismatch);
                }

                $applied = $this->applyPaymentResult->handle($payment, PaymentResult::fromWebhook($notification));

                $this->finish(
                    $event,
                    $applied->accepted ? WebhookEventStatus::Processed : WebhookEventStatus::Ignored,
                    $applied->reasonCode,
                );

                return new WebhookProcessingResult(
                    $applied->accepted ? WebhookProcessingStatus::Processed : WebhookProcessingStatus::Ignored,
                    $applied->reasonCode,
                );
            });
        } catch (Throwable $e) {
            // El efecto y la marca de "hecho" volvieron atrás juntos; el fallo
            // se registra aparte para que el evento siga siendo reprocesable.
            $this->recordFailure($notification, $e);

            throw $e;
        }
    }

    private function claimIdentity(WebhookNotification $notification): void
    {
        DB::table('webhook_events')->insertOrIgnore([
            'provider' => $this->gateway->name(),
            'external_event_id' => $notification->eventId,
            'payment_external_id' => $notification->externalPaymentId,
            'payload' => json_encode($notification->payload, JSON_THROW_ON_ERROR),
            'status' => WebhookEventStatus::Received->value,
            'attempts' => 0,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mismatchReason(Payment $payment, WebhookNotification $notification): ?string
    {
        if (bccomp((string) $payment->amount, $notification->amount, 2) !== 0) {
            return 'amount_mismatch';
        }

        if (strtoupper($payment->currency) !== strtoupper($notification->currency)) {
            return 'currency_mismatch';
        }

        return null;
    }

    private function finish(WebhookEvent $event, WebhookEventStatus $status, string $reason, bool $incrementAttempts = false): void
    {
        $event->update([
            'status' => $status,
            'outcome_reason' => $reason,
            'processed_at' => now(),
            'attempts' => $incrementAttempts ? $event->attempts + 1 : $event->attempts,
        ]);
    }

    private function recordFailure(WebhookNotification $notification, Throwable $e): void
    {
        try {
            WebhookEvent::where('provider', $this->gateway->name())
                ->where('external_event_id', $notification->eventId)
                // Nunca pisar una fila que otra entrega concurrente ya llevó a
                // un resultado terminal: un fallo obsoleto jamás debe ganarle
                // a un éxito real ya confirmado.
                ->whereNotIn('status', [WebhookEventStatus::Processed->value, WebhookEventStatus::Ignored->value])
                ->update([
                    'status' => WebhookEventStatus::Failed,
                    'outcome_reason' => 'internal_error',
                    // Solo el mensaje: nunca payload, firma ni secreto.
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $inner) {
            Log::error('payments.webhook_failure_record_failed', ['message' => $inner->getMessage()]);
        }
    }
}
