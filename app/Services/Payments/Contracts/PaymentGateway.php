<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\CheckoutResult;
use App\Services\Payments\Data\ProviderSnapshot;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\Data\WebhookNotification;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Services\Payments\Exceptions\MalformedWebhookPayloadException;
use App\Services\Payments\Exceptions\UnknownProviderPaymentException;
use DateTimeImmutable;

interface PaymentGateway
{
    /** Identificador estable del proveedor; se persiste en `payments.provider`. */
    public function name(): string;

    /** @throws GatewayUnavailableException */
    public function createCheckout(CheckoutRequest $request): CheckoutResult;

    /**
     * URL efímera de checkout para un intento vivo. Se genera en cada respuesta
     * y nunca se persiste: una URL absoluta guardada en base queda atada al
     * entorno donde se creó.
     */
    public function checkoutUrl(string $externalId, DateTimeImmutable $expiresAt): string;

    /**
     * @throws InvalidWebhookSignatureException
     * @throws MalformedWebhookPayloadException
     */
    public function parseWebhook(WebhookEnvelope $envelope): WebhookNotification;

    /**
     * @throws GatewayUnavailableException
     * @throws UnknownProviderPaymentException
     */
    public function fetchPayment(string $externalId): ProviderSnapshot;
}
