<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Services\Payments\Exceptions\MalformedWebhookPayloadException;
use App\Services\Payments\Exceptions\UnknownProviderPaymentException;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use App\Services\Payments\Simulated\SimulatedProviderPayment;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimulatedPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function gateway(): SimulatedPaymentGateway
    {
        return app(PaymentGateway::class);
    }

    private function checkoutRequest(?DateTimeImmutable $expiresAt = null): CheckoutRequest
    {
        return new CheckoutRequest(
            reference: (string) Str::ulid(),
            amount: '10.00',
            currency: 'ARS',
            description: 'Seña de Corte de pelo',
            returnUrl: 'http://localhost/mis-reservas',
            expiresAt: $expiresAt ?? new DateTimeImmutable('+30 minutes'),
        );
    }

    public function test_the_container_binds_the_simulated_gateway(): void
    {
        $this->assertInstanceOf(SimulatedPaymentGateway::class, app(PaymentGateway::class));
        $this->assertSame('simulated', app(PaymentGateway::class)->name());
    }

    public function test_create_checkout_persists_independent_provider_state(): void
    {
        $request = $this->checkoutRequest();

        $result = $this->gateway()->createCheckout($request);

        $this->assertStringStartsWith('sim_pay_', $result->externalId);
        $this->assertSame(PaymentStatus::Pending, $result->status);

        $providerRow = SimulatedProviderPayment::where('external_id', $result->externalId)->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $providerRow->status);
        $this->assertSame('10.00', $providerRow->amount);
        $this->assertSame('ARS', $providerRow->currency);
        $this->assertSame($request->reference, $providerRow->payload['reference']);
    }

    public function test_checkout_url_is_signed_and_fresh(): void
    {
        $result = $this->gateway()->createCheckout($this->checkoutRequest());
        $expiresAt = new DateTimeImmutable('+30 minutes');

        $url = $this->gateway()->checkoutUrl($result->externalId, $expiresAt);

        $this->assertStringContainsString("/demo/pagos/{$result->externalId}/checkout", $url);
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)->assertOk();
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    public function test_fetch_payment_reads_provider_state_and_expires_it_on_its_own(): void
    {
        $result = $this->gateway()->createCheckout($this->checkoutRequest(new DateTimeImmutable('-1 minute')));

        $snapshot = $this->gateway()->fetchPayment($result->externalId);

        $this->assertSame(PaymentStatus::Expired, $snapshot->status);
        $this->assertSame(
            PaymentStatus::Expired,
            SimulatedProviderPayment::where('external_id', $result->externalId)->firstOrFail()->status,
        );
    }

    public function test_fetch_payment_rejects_an_unknown_external_id(): void
    {
        $this->expectException(UnknownProviderPaymentException::class);

        $this->gateway()->fetchPayment('sim_pay_does_not_exist');
    }

    public function test_the_provider_lifecycle_is_monotonic(): void
    {
        $expired = $this->gateway()->createCheckout($this->checkoutRequest(new DateTimeImmutable('-1 minute')));
        $this->gateway()->fetchPayment($expired->externalId);

        $this->assertFalse($this->gateway()->applyOutcome($expired->externalId, PaymentStatus::Approved));
        $this->assertSame(PaymentStatus::Expired, $this->gateway()->fetchPayment($expired->externalId)->status);

        $live = $this->gateway()->createCheckout($this->checkoutRequest());
        $this->assertTrue($this->gateway()->applyOutcome($live->externalId, PaymentStatus::Approved));
        $this->assertFalse($this->gateway()->applyOutcome($live->externalId, PaymentStatus::Rejected));
        $this->assertSame(PaymentStatus::Approved, $this->gateway()->fetchPayment($live->externalId)->status);
    }

    public function test_parse_webhook_accepts_a_valid_signature(): void
    {
        $gateway = $this->gateway();
        $result = $gateway->createCheckout($this->checkoutRequest());
        $payload = $gateway->payloadFor($result->externalId, PaymentStatus::Approved, 'evt_1');
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $notification = $gateway->parseWebhook(new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
        ]));

        $this->assertSame('evt_1', $notification->eventId);
        $this->assertSame($result->externalId, $notification->externalPaymentId);
        $this->assertSame(PaymentStatus::Approved, $notification->status);
        $this->assertSame('10.00', $notification->amount);
        $this->assertSame('ARS', $notification->currency);
    }

    public function test_parse_webhook_rejects_a_wrong_signature(): void
    {
        $gateway = $this->gateway();
        $rawBody = json_encode($gateway->payloadFor('sim_pay_x', PaymentStatus::Approved, 'evt_2'), JSON_THROW_ON_ERROR);

        $this->expectException(InvalidWebhookSignatureException::class);

        $gateway->parseWebhook(new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => 't='.time().',v1=deadbeef',
        ]));
    }

    public function test_parse_webhook_rejects_a_stale_timestamp(): void
    {
        $gateway = $this->gateway();
        $rawBody = json_encode($gateway->payloadFor('sim_pay_x', PaymentStatus::Approved, 'evt_3'), JSON_THROW_ON_ERROR);
        $stale = new DateTimeImmutable('-'.(config('payments.webhook_tolerance_seconds') + 60).' seconds');

        $this->expectException(InvalidWebhookSignatureException::class);

        $gateway->parseWebhook(new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody, $stale),
        ]));
    }

    public function test_parse_webhook_rejects_a_malformed_body(): void
    {
        $gateway = $this->gateway();
        $rawBody = '{"nope":true}';

        $this->expectException(MalformedWebhookPayloadException::class);

        $gateway->parseWebhook(new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
        ]));
    }

    public function test_parse_webhook_returns_a_redacted_payload(): void
    {
        $gateway = $this->gateway();
        $payload = $gateway->payloadFor('sim_pay_x', PaymentStatus::Rejected, 'evt_4') + ['card_number' => '4111111111111111'];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $notification = $gateway->parseWebhook(new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
        ]));

        $this->assertArrayNotHasKey('card_number', $notification->payload);
        $this->assertSame('rejected', $notification->payload['status']);
    }
}
