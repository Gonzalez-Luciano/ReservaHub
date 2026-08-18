<?php

namespace Tests\Feature\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Jobs\DeliverSimulatedProviderWebhook;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\ProcessPaymentWebhook;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DeliverSimulatedProviderWebhookTest extends TestCase
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

        $checkout = app(PaymentGateway::class)->createCheckout(new CheckoutRequest(
            reference: (string) Str::ulid(),
            amount: '10.00',
            currency: 'ARS',
            description: 'Seña',
            returnUrl: 'http://localhost/mis-reservas',
            expiresAt: new DateTimeImmutable('+30 minutes'),
        ));

        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'external_id' => $checkout->externalId,
            'amount' => '10.00',
            'currency' => 'ARS',
        ]);

        return [$booking, $payment];
    }

    public function test_the_job_delivers_the_event_through_the_shared_boundary(): void
    {
        [$booking, $payment] = $this->scenario();
        app(PaymentGateway::class)->applyOutcome($payment->external_id, PaymentStatus::Approved);

        (new DeliverSimulatedProviderWebhook($payment->external_id, PaymentStatus::Approved, 'evt_job_1'))
            ->handle(app(PaymentGateway::class), app(ProcessPaymentWebhook::class));

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertSame('processed', WebhookEvent::where('external_event_id', 'evt_job_1')->firstOrFail()->status->value);
    }

    public function test_a_queue_delay_longer_than_the_tolerance_still_delivers(): void
    {
        [$booking, $payment] = $this->scenario();
        app(PaymentGateway::class)->applyOutcome($payment->external_id, PaymentStatus::Approved);

        // La firma se calcula en handle(): un job encolado hace mucho sigue
        // produciendo una marca temporal fresca y válida.
        $this->travel(config('payments.webhook_tolerance_seconds') + 120)->seconds();

        (new DeliverSimulatedProviderWebhook($payment->external_id, PaymentStatus::Approved, 'evt_job_delayed'))
            ->handle(app(PaymentGateway::class), app(ProcessPaymentWebhook::class));

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_a_retryable_failure_throws_so_the_queue_retries(): void
    {
        [, $payment] = $this->scenario();

        $this->expectException(RuntimeException::class);

        (new DeliverSimulatedProviderWebhook('sim_pay_orphan_not_local', PaymentStatus::Approved, 'evt_job_fail'))
            ->handle(app(PaymentGateway::class), app(ProcessPaymentWebhook::class));
    }

    public function test_a_retry_keeps_the_same_logical_event_id(): void
    {
        [$booking, $payment] = $this->scenario();
        app(PaymentGateway::class)->applyOutcome($payment->external_id, PaymentStatus::Approved);
        $job = new DeliverSimulatedProviderWebhook($payment->external_id, PaymentStatus::Approved, 'evt_job_retry');

        $job->handle(app(PaymentGateway::class), app(ProcessPaymentWebhook::class));
        $job->handle(app(PaymentGateway::class), app(ProcessPaymentWebhook::class));

        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt_job_retry')->count());
        $this->assertSame(1, $booking->refresh()->statusHistories()->where('to_status', BookingStatus::Confirmed)->count());
    }

    public function test_the_job_is_configured_for_retries_and_after_commit(): void
    {
        $job = new DeliverSimulatedProviderWebhook('sim_pay_x', PaymentStatus::Approved, 'evt_cfg');

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff());
        $this->assertSame(15, $job->timeout);
        $this->assertTrue($job->afterCommit);
    }

    public function test_dispatching_queues_the_job(): void
    {
        Queue::fake();

        DeliverSimulatedProviderWebhook::dispatch('sim_pay_x', PaymentStatus::Approved, 'evt_dispatch');

        Queue::assertPushed(DeliverSimulatedProviderWebhook::class);
    }
}
