<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookProcessingStatus;
use App\Http\Controllers\Controller;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Services\Payments\Exceptions\MalformedWebhookPayloadException;
use App\Services\Payments\ProcessPaymentWebhook;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller fino: traduce HTTP ↔ dominio y nada más. Toda la semántica de
 * firma, idempotencia y aplicación vive en ProcessPaymentWebhook.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        PaymentGateway $gateway,
        ProcessPaymentWebhook $processor,
    ): JsonResponse {
        // El proveedor de la ruta se compara contra el binding real: nunca se
        // instancia una clase a partir de un string del request.
        abort_unless($provider === $gateway->name(), 404);

        // La firma se verifica contra el cuerpo crudo exacto, no contra el
        // array ya parseado por Laravel.
        $envelope = new WebhookEnvelope($request->getContent(), $request->headers->all());

        try {
            $result = $processor->handle($envelope);
        } catch (InvalidWebhookSignatureException $e) {
            // Ni cuerpo ni firma en el log: solo el motivo y el origen.
            Log::warning('payments.webhook_invalid_signature', [
                'provider' => $provider,
                'ip' => $request->ip(),
                'reason' => $e->getMessage(),
            ]);

            return ApiResponse::error('Firma del webhook inválida.', null, 401);
        } catch (MalformedWebhookPayloadException $e) {
            Log::warning('payments.webhook_malformed_payload', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            return ApiResponse::error('El cuerpo del webhook no es válido.', null, 422);
        }

        if ($result->status === WebhookProcessingStatus::Failed) {
            // 500 a propósito: el proveedor debe reintentar.
            return ApiResponse::error('No se pudo procesar el webhook.', null, 500);
        }

        return ApiResponse::success(
            ['status' => $result->status->value, 'reason' => $result->reason],
            'Webhook recibido.',
        );
    }
}
