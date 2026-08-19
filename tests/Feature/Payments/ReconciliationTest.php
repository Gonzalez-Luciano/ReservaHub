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
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $paymentOverrides
     * @return array{0: Booking, 1: Payment}
     */
    private function scenario(array $paymentOverrides = [], ?DateTimeImmutable $providerExpiry = null): array
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
            expiresAt: $providerExpiry ?? new DateTimeImmutable('+30 minutes'),
        ));

        $payment = Payment::factory()->for($booking)->create(array_merge([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'external_id' => $checkout->externalId,
            'amount' => '10.00',
            'currency' => 'ARS',
        ], $paymentOverrides));

        return [$booking, $payment];
    }

    public function test_it_applies_an_approved_provider_state_and_confirms_the_booking(): void
    {
        [$booking, $payment] = $this->scenario();
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);
        // El proveedor aprueba, pero la entrega del evento "se pierde".
        $gateway->applyOutcome($payment->external_id, PaymentStatus::Approved);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertNotNull($payment->last_reconciled_at);
        $this->assertNotNull($payment->last_reconcile_attempt_at);
        // La reconciliación no es una entrega de evento.
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_it_applies_a_rejected_provider_state_without_confirming(): void
    {
        [$booking, $payment] = $this->scenario();
        app(PaymentGateway::class)->applyOutcome($payment->external_id, PaymentStatus::Rejected);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Rejected, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_it_applies_provider_side_expiry(): void
    {
        [$booking, $payment] = $this->scenario(providerExpiry: new DateTimeImmutable('-1 minute'));

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Expired, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_a_still_pending_provider_only_refreshes_the_snapshot(): void
    {
        [$booking, $payment] = $this->scenario();

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertNotNull($payment->last_reconciled_at);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_terminal_payments_are_never_selected(): void
    {
        [, $payment] = $this->scenario(['status' => PaymentStatus::Rejected]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertNull($payment->refresh()->last_reconcile_attempt_at);
    }

    public function test_an_old_pending_payment_is_still_eligible(): void
    {
        [$booking, $payment] = $this->scenario();
        $payment->forceFill(['created_at' => now()->subMonths(6)])->save();
        app(PaymentGateway::class)->applyOutcome($payment->external_id, PaymentStatus::Approved);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_a_provider_outage_stamps_the_attempt_but_not_the_success(): void
    {
        [, $payment] = $this->scenario();

        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('name')->andReturn('simulated');
            $mock->shouldReceive('fetchPayment')->andThrow(new GatewayUnavailableException('caído'));
        });

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $payment->refresh();
        $this->assertNotNull($payment->last_reconcile_attempt_at);
        $this->assertNull($payment->last_reconciled_at);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function test_a_failing_subset_cannot_starve_the_rest_of_the_batch(): void
    {
        config(['payments.reconcile.batch' => 2]);

        $payments = [];

        for ($i = 0; $i < 4; $i++) {
            [, $payment] = $this->scenario();
            $payments[] = $payment;
        }

        // Los dos primeros fallan siempre; los otros dos responden bien.
        $failing = [$payments[0]->external_id, $payments[1]->external_id];
        $real = app(SimulatedPaymentGateway::class);

        $this->mock(PaymentGateway::class, function ($mock) use ($failing, $real) {
            $mock->shouldReceive('name')->andReturn('simulated');
            $mock->shouldReceive('fetchPayment')->andReturnUsing(function (string $externalId) use ($failing, $real) {
                if (in_array($externalId, $failing, true)) {
                    throw new GatewayUnavailableException('caído');
                }

                return $real->fetchPayment($externalId);
            });
        });

        // Primera corrida: toma los dos que fallan (attempt nulo, orden NULLS FIRST).
        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertNotNull($payments[0]->refresh()->last_reconcile_attempt_at);
        $this->assertNull($payments[2]->refresh()->last_reconcile_attempt_at);

        // Segunda corrida: como el intento se estampa aunque falle, los que
        // fallan salen del frente y los siguientes se inspeccionan.
        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertNotNull($payments[2]->refresh()->last_reconcile_attempt_at);
        $this->assertNotNull($payments[3]->refresh()->last_reconcile_attempt_at);
        $this->assertNotNull($payments[2]->last_reconciled_at);
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        [$booking, $payment] = $this->scenario();
        app(PaymentGateway::class)->applyOutcome($payment->external_id, PaymentStatus::Approved);

        $this->artisan('payments:reconcile')->assertExitCode(0);
        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(1, $booking->refresh()->statusHistories()->where('to_status', BookingStatus::Confirmed)->count());
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
    }

    public function test_an_unknown_provider_payment_is_logged_without_changing_state(): void
    {
        [, $payment] = $this->scenario();
        $payment->forceFill(['external_id' => 'sim_pay_vanished'])->save();

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNotNull($payment->last_reconcile_attempt_at);
        $this->assertNull($payment->last_reconciled_at);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
