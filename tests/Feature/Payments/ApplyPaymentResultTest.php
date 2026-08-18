<?php

namespace Tests\Feature\Payments;

use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Payments\ApplyPaymentResult;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationReason;
use App\Enums\PaymentApplicationOutcome;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingConfirmedNotification;
use App\Services\Payments\Data\PaymentResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\TestCase;

class ApplyPaymentResultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $bookingOverrides
     * @param  array<string, mixed>  $paymentOverrides
     * @return array{0: Booking, 1: Payment, 2: User}
     */
    private function scenario(array $bookingOverrides = [], array $paymentOverrides = []): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
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

        $payment = Payment::factory()->for($booking)->create(array_merge([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'amount' => '10.00',
            'currency' => 'ARS',
        ], $paymentOverrides));

        return [$booking, $payment, $owner];
    }

    private function paymentResult(PaymentStatus $status): PaymentResult
    {
        return new PaymentResult(
            status: $status,
            amount: '10.00',
            currency: 'ARS',
            occurredAt: new DateTimeImmutable,
            snapshot: ['status' => $status->value],
            failureReason: $status === PaymentStatus::Rejected ? 'rejected_by_provider' : null,
        );
    }

    public function test_an_approved_result_confirms_the_booking_once(): void
    {
        Notification::fake();
        [$booking, $payment] = $this->scenario();

        $applied = app(ApplyPaymentResult::class)->handle($payment, $this->paymentResult(PaymentStatus::Approved));

        $this->assertTrue($applied->accepted);
        $this->assertSame(PaymentApplicationOutcome::BookingConfirmed, $applied->outcome);
        $this->assertSame('booking_confirmed', $applied->reasonCode);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Approved, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->applied_at);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);

        $history = $booking->statusHistories()->latest('id')->first();
        $this->assertNull($history->changed_by);
        $this->assertStringContainsString("#{$payment->id}", $history->notes);

        Notification::assertSentToTimes($booking->customer, BookingConfirmedNotification::class, 1);
    }

    public function test_an_approved_result_on_a_cancelled_booking_is_recorded_but_not_applied(): void
    {
        Notification::fake();
        [$booking, $payment] = $this->scenario(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        $applied = app(ApplyPaymentResult::class)->handle($payment, $this->paymentResult(PaymentStatus::Approved));

        $this->assertTrue($applied->accepted);
        $this->assertSame(PaymentApplicationOutcome::BookingNotPending, $applied->outcome);
        $this->assertSame('booking_not_pending', $applied->reasonCode);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Approved, $payment->status);
        $this->assertNull($payment->applied_at);
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);

        Notification::assertNothingSent();
    }

    public function test_rejected_and_expired_results_are_accepted_without_touching_the_booking(): void
    {
        [$booking, $payment] = $this->scenario();

        $applied = app(ApplyPaymentResult::class)->handle($payment, $this->paymentResult(PaymentStatus::Rejected));

        $this->assertTrue($applied->accepted);
        $this->assertSame(PaymentApplicationOutcome::NoAction, $applied->outcome);
        $this->assertSame('rejected', $applied->reasonCode);
        $this->assertSame('rejected_by_provider', $payment->refresh()->failure_reason);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);

        [$otherBooking, $otherPayment] = $this->scenario();
        $expired = app(ApplyPaymentResult::class)->handle($otherPayment, $this->paymentResult(PaymentStatus::Expired));

        $this->assertTrue($expired->accepted);
        $this->assertSame('expired', $expired->reasonCode);
        $this->assertSame(PaymentStatus::Expired, $otherPayment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $otherBooking->refresh()->status);
    }

    public function test_a_still_pending_provider_is_a_valid_observation(): void
    {
        [$booking, $payment] = $this->scenario();

        $applied = app(ApplyPaymentResult::class)->handle($payment, $this->paymentResult(PaymentStatus::Pending));

        $this->assertTrue($applied->accepted);
        $this->assertSame(PaymentApplicationOutcome::NoAction, $applied->outcome);
        $this->assertSame('provider_still_pending', $applied->reasonCode);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(['status' => 'pending'], $payment->last_snapshot);
    }

    public function test_a_terminal_payment_never_moves_again(): void
    {
        [$booking, $payment] = $this->scenario([], ['status' => PaymentStatus::Expired]);

        $applied = app(ApplyPaymentResult::class)->handle($payment, $this->paymentResult(PaymentStatus::Approved));

        $this->assertFalse($applied->accepted);
        $this->assertSame(PaymentApplicationOutcome::NoAction, $applied->outcome);
        $this->assertSame('payment_already_terminal', $applied->reasonCode);
        $this->assertSame(PaymentStatus::Expired, $payment->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_applying_the_same_approval_twice_confirms_only_once(): void
    {
        Notification::fake();
        [$booking, $payment] = $this->scenario();

        app(ApplyPaymentResult::class)->handle($payment, $this->paymentResult(PaymentStatus::Approved));
        $second = app(ApplyPaymentResult::class)->handle($payment->refresh(), $this->paymentResult(PaymentStatus::Approved));

        $this->assertFalse($second->accepted);
        $this->assertSame(1, $booking->refresh()->statusHistories()->where('to_status', BookingStatus::Confirmed)->count());
        Notification::assertSentToTimes($booking->customer, BookingConfirmedNotification::class, 1);
    }

    public function test_manual_confirmation_still_requires_an_actor(): void
    {
        [$booking, , $owner] = $this->scenario();

        $confirmed = app(ConfirmBooking::class)->handle($booking, $owner);
        $this->assertSame(BookingStatus::Confirmed, $confirmed->status);
        $this->assertSame($owner->id, $booking->statusHistories()->latest('id')->first()->changed_by);
    }

    public function test_confirmation_contexts_are_validated(): void
    {
        [$booking, $payment, $owner] = $this->scenario();

        try {
            app(ConfirmBooking::class)->handle($booking, null);
            $this->fail('Se esperaba InvalidArgumentException por confirmación manual sin actor.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            app(ConfirmBooking::class)->handle($booking, $owner, ConfirmationReason::PaymentApproved, $payment);
            $this->fail('Se esperaba InvalidArgumentException por confirmación de sistema con actor.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            app(ConfirmBooking::class)->handle($booking, null, ConfirmationReason::PaymentApproved, null);
            $this->fail('Se esperaba InvalidArgumentException por confirmación de pago sin pago.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }
}
