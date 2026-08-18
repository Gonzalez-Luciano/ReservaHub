<?php

namespace Tests\Feature\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\WebhookEventStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\Bookings\BookingConfirmedNotification;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\ProcessPaymentWebhook;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $bookingOverrides
     * @return array{0: Booking, 1: Payment}
     */
    private function scenario(array $bookingOverrides = []): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => '10.00']);

        $booking = Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
            'payment_expires_at' => now()->addMinutes(30),
        ], $bookingOverrides));

        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'amount' => '10.00',
            'currency' => 'ARS',
        ]);

        return [$booking, $payment];
    }

    private function envelope(Payment $payment, PaymentStatus $status, string $eventId, array $overrides = []): WebhookEnvelope
    {
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        $payload = array_merge([
            'event_id' => $eventId,
            'payment_id' => $payment->external_id,
            'status' => $status->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);

        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        return new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
        ]);
    }

    public function test_an_approved_event_is_processed_once(): void
    {
        Notification::fake();
        [$booking, $payment] = $this->scenario();

        $result = app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_1'));

        $this->assertSame(WebhookProcessingStatus::Processed, $result->status);
        $this->assertSame('booking_confirmed', $result->reason);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);

        $event = WebhookEvent::where('external_event_id', 'evt_1')->firstOrFail();
        $this->assertSame(WebhookEventStatus::Processed, $event->status);
        $this->assertSame('booking_confirmed', $event->outcome_reason);
        $this->assertNotNull($event->processed_at);
        $this->assertSame($payment->external_id, $event->payment_external_id);
        Notification::assertSentToTimes($booking->customer, BookingConfirmedNotification::class, 1);
    }

    public function test_a_duplicate_delivery_has_no_effect_and_preserves_the_original_outcome(): void
    {
        Notification::fake();
        [$booking, $payment] = $this->scenario();

        app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_1'));
        $originalProcessedAt = WebhookEvent::where('external_event_id', 'evt_1')->firstOrFail()->processed_at;

        $second = app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_1'));

        $this->assertSame(WebhookProcessingStatus::Duplicate, $second->status);
        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt_1')->count());

        $event = WebhookEvent::where('external_event_id', 'evt_1')->firstOrFail();
        $this->assertSame(WebhookEventStatus::Processed, $event->status);
        $this->assertSame('booking_confirmed', $event->outcome_reason);
        $this->assertEquals($originalProcessedAt, $event->processed_at);

        $this->assertSame(1, $booking->refresh()->statusHistories()->where('to_status', BookingStatus::Confirmed)->count());
        Notification::assertSentToTimes($booking->customer, BookingConfirmedNotification::class, 1);
    }

    public function test_an_unknown_external_payment_is_a_retryable_failure(): void
    {
        [$booking, $payment] = $this->scenario();
        $payment->forceFill(['external_id' => 'sim_pay_orphan'])->saveQuietly();
        $envelope = $this->envelope($payment, PaymentStatus::Approved, 'evt_orphan');
        $payment->forceFill(['external_id' => 'sim_pay_real'])->saveQuietly();

        $result = app(ProcessPaymentWebhook::class)->handle($envelope);

        $this->assertSame(WebhookProcessingStatus::Failed, $result->status);

        $event = WebhookEvent::where('external_event_id', 'evt_orphan')->firstOrFail();
        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertSame('unknown_payment', $event->outcome_reason);
        $this->assertSame(1, $event->attempts);
    }

    public function test_a_failed_event_is_reprocessable_on_redelivery(): void
    {
        [$booking, $payment] = $this->scenario();
        $envelope = $this->envelope($payment, PaymentStatus::Approved, 'evt_retry');

        WebhookEvent::factory()->create([
            'provider' => 'simulated',
            'external_event_id' => 'evt_retry',
            'payment_external_id' => $payment->external_id,
            'status' => WebhookEventStatus::Failed,
            'attempts' => 1,
        ]);

        $result = app(ProcessPaymentWebhook::class)->handle($envelope);

        $this->assertSame(WebhookProcessingStatus::Processed, $result->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertSame(WebhookEventStatus::Processed, WebhookEvent::where('external_event_id', 'evt_retry')->firstOrFail()->status);
    }

    public function test_a_received_event_left_behind_by_a_crash_is_reprocessable(): void
    {
        [$booking, $payment] = $this->scenario();

        WebhookEvent::factory()->create([
            'provider' => 'simulated',
            'external_event_id' => 'evt_crashed',
            'payment_external_id' => $payment->external_id,
            'status' => WebhookEventStatus::Received,
        ]);

        $result = app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_crashed'));

        $this->assertSame(WebhookProcessingStatus::Processed, $result->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_an_amount_mismatch_is_recorded_and_ignored(): void
    {
        [$booking, $payment] = $this->scenario();

        $result = app(ProcessPaymentWebhook::class)->handle(
            $this->envelope($payment, PaymentStatus::Approved, 'evt_amount', ['amount' => '999.00']),
        );

        $this->assertSame(WebhookProcessingStatus::Ignored, $result->status);
        $this->assertSame('amount_mismatch', $result->reason);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::where('external_event_id', 'evt_amount')->firstOrFail()->status);
    }

    public function test_a_currency_mismatch_is_recorded_and_ignored(): void
    {
        [$booking, $payment] = $this->scenario();

        $result = app(ProcessPaymentWebhook::class)->handle(
            $this->envelope($payment, PaymentStatus::Approved, 'evt_currency', ['currency' => 'USD']),
        );

        $this->assertSame(WebhookProcessingStatus::Ignored, $result->status);
        $this->assertSame('currency_mismatch', $result->reason);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_a_late_approval_on_a_cancelled_booking_is_processed_without_resurrection(): void
    {
        Notification::fake();
        [$booking, $payment] = $this->scenario(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        $result = app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_late'));

        $this->assertSame(WebhookProcessingStatus::Processed, $result->status);
        $this->assertSame('booking_not_pending', $result->reason);
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertNull($payment->applied_at);
        Notification::assertNothingSent();
    }

    public function test_a_stale_event_against_a_terminal_payment_is_ignored(): void
    {
        [$booking, $payment] = $this->scenario();
        $payment->forceFill(['status' => PaymentStatus::Rejected])->save();

        $result = app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_stale'));

        $this->assertSame(WebhookProcessingStatus::Ignored, $result->status);
        $this->assertSame('payment_already_terminal', $result->reason);
        $this->assertSame(PaymentStatus::Rejected, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_the_persisted_payload_is_redacted(): void
    {
        [, $payment] = $this->scenario();

        app(ProcessPaymentWebhook::class)->handle(
            $this->envelope($payment, PaymentStatus::Approved, 'evt_redact', ['card_number' => '4111111111111111']),
        );

        $event = WebhookEvent::where('external_event_id', 'evt_redact')->firstOrFail();

        $this->assertArrayNotHasKey('card_number', $event->payload);
        $this->assertSame('approved', $event->payload['status']);
    }
}
