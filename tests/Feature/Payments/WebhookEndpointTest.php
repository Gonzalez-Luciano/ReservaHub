<?php

namespace Tests\Feature\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Booking, 1: Payment}
     */
    private function scenario(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => '10.00']);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
            'payment_expires_at' => now()->addMinutes(30),
        ]);

        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'amount' => '10.00',
            'currency' => 'ARS',
        ]);

        return [$booking, $payment];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: string, 1: array<string, string>}
     */
    private function signedBody(Payment $payment, PaymentStatus $status, string $eventId, array $overrides = []): array
    {
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        $rawBody = json_encode(array_merge([
            'event_id' => $eventId,
            'payment_id' => $payment->external_id,
            'status' => $status->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'occurred_at' => now()->toIso8601String(),
        ], $overrides), JSON_THROW_ON_ERROR);

        return [$rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]];
    }

    public function test_a_valid_signed_delivery_confirms_the_booking(): void
    {
        [$booking, $payment] = $this->scenario();
        [$rawBody, $headers] = $this->signedBody($payment, PaymentStatus::Approved, 'evt_http_1');

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders($headers), $rawBody)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
    }

    public function test_a_duplicate_delivery_still_answers_200(): void
    {
        [, $payment] = $this->scenario();
        [$rawBody, $headers] = $this->signedBody($payment, PaymentStatus::Approved, 'evt_http_dup');
        $server = $this->serverHeaders($headers);

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $server, $rawBody)->assertOk();
        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $server, $rawBody)->assertOk();

        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt_http_dup')->count());
    }

    public function test_an_invalid_signature_is_rejected_without_persistence(): void
    {
        [, $payment] = $this->scenario();
        [$rawBody] = $this->signedBody($payment, PaymentStatus::Approved, 'evt_http_bad');

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders([
            'X-ReservaHub-Signature' => 't='.time().',v1=deadbeef',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), $rawBody)->assertStatus(401)->assertJsonPath('success', false);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        [, $payment] = $this->scenario();
        [$rawBody] = $this->signedBody($payment, PaymentStatus::Approved, 'evt_http_nosig');

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), $rawBody)->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_an_unknown_provider_is_a_404(): void
    {
        [, $payment] = $this->scenario();
        [$rawBody, $headers] = $this->signedBody($payment, PaymentStatus::Approved, 'evt_http_provider');

        $this->call('POST', '/api/webhooks/payments/stripe', [], [], [], $this->serverHeaders($headers), $rawBody)
            ->assertStatus(404);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_a_malformed_payload_is_a_422_without_persistence(): void
    {
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $rawBody = '{"nope":true}';

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders([
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), $rawBody)->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_an_unknown_payment_answers_500_so_the_provider_retries(): void
    {
        [, $payment] = $this->scenario();
        [$rawBody, $headers] = $this->signedBody($payment, PaymentStatus::Approved, 'evt_http_orphan', [
            'payment_id' => 'sim_pay_orphan',
        ]);

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders($headers), $rawBody)
            ->assertStatus(500);

        $this->assertSame('failed', WebhookEvent::where('external_event_id', 'evt_http_orphan')->firstOrFail()->status->value);
    }

    public function test_a_pending_delivery_changes_nothing(): void
    {
        [$booking, $payment] = $this->scenario();
        [$rawBody, $headers] = $this->signedBody($payment, PaymentStatus::Pending, 'evt_http_pending');

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders($headers), $rawBody)
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_a_rejected_delivery_does_not_confirm(): void
    {
        [$booking, $payment] = $this->scenario();
        [$rawBody, $headers] = $this->signedBody($payment, PaymentStatus::Rejected, 'evt_http_rejected');

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders($headers), $rawBody)
            ->assertOk();

        $this->assertSame(PaymentStatus::Rejected, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_the_endpoint_needs_no_authentication_and_no_business_context(): void
    {
        [, $payment] = $this->scenario();
        [$rawBody, $headers] = $this->signedBody($payment, PaymentStatus::Approved, 'evt_http_anon');

        $this->assertGuest();

        $this->call('POST', '/api/webhooks/payments/simulated', [], [], [], $this->serverHeaders($headers), $rawBody)
            ->assertOk();
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }
}
