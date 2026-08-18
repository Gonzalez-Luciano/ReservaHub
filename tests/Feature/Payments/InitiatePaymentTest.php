<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\InitiatePayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InitiatePaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Booking, 1: User, 2: Business}
     */
    private function depositBooking(array $overrides = []): array
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
        ], $overrides));

        return [$booking, $customer, $business];
    }

    public function test_it_creates_a_pending_payment_with_application_owned_amounts(): void
    {
        [$booking, $customer, $business] = $this->depositBooking();

        $payment = app(InitiatePayment::class)->handle($booking, $customer);

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('10.00', $payment->amount);
        $this->assertSame('ARS', $payment->currency);
        $this->assertSame($business->id, $payment->business_id);
        $this->assertNotEmpty($payment->external_id);
        $this->assertSame($booking->payment_expires_at->getTimestamp(), $payment->expires_at->getTimestamp());
    }

    public function test_repeating_initiation_returns_the_live_attempt(): void
    {
        [$booking, $customer] = $this->depositBooking();

        $first = app(InitiatePayment::class)->handle($booking, $customer);
        $second = app(InitiatePayment::class)->handle($booking->refresh(), $customer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->count());
    }

    public function test_a_booking_without_deposit_cannot_be_paid(): void
    {
        [$booking, $customer] = $this->depositBooking([
            'deposit_amount' => null,
            'status' => BookingStatus::Confirmed,
            'payment_expires_at' => null,
        ]);

        $this->expectException(ValidationException::class);

        app(InitiatePayment::class)->handle($booking, $customer);
    }

    public function test_a_booking_that_is_not_pending_cannot_be_paid(): void
    {
        [$booking, $customer] = $this->depositBooking(['status' => BookingStatus::Cancelled]);

        $this->expectException(ValidationException::class);

        app(InitiatePayment::class)->handle($booking, $customer);
    }

    public function test_an_expired_window_cannot_be_paid_even_before_the_sweeper_runs(): void
    {
        [$booking, $customer] = $this->depositBooking(['payment_expires_at' => now()->subMinute()]);

        $this->expectException(ValidationException::class);

        app(InitiatePayment::class)->handle($booking, $customer);
    }

    public function test_a_legacy_booking_without_a_window_cannot_be_paid(): void
    {
        [$booking, $customer] = $this->depositBooking(['payment_expires_at' => null]);

        $this->expectException(ValidationException::class);

        app(InitiatePayment::class)->handle($booking, $customer);
    }

    public function test_a_gateway_failure_leaves_no_local_row(): void
    {
        [$booking, $customer] = $this->depositBooking();

        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('name')->andReturn('simulated');
            $mock->shouldReceive('createCheckout')->andThrow(new GatewayUnavailableException('proveedor caído'));
        });

        try {
            app(InitiatePayment::class)->handle($booking, $customer);
            $this->fail('Se esperaba GatewayUnavailableException.');
        } catch (GatewayUnavailableException) {
            // esperado
        }

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }
}
