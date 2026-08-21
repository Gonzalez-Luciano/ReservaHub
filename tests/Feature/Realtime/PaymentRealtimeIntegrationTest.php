<?php

namespace Tests\Feature\Realtime;

use App\Enums\BookingChange;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Events\Broadcasting\BookingChanged;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\ProcessPaymentWebhook;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentRealtimeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
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

    private function approvedEnvelope(Payment $payment, string $eventId): WebhookEnvelope
    {
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        $rawBody = json_encode([
            'event_id' => $eventId,
            'payment_id' => $payment->external_id,
            'status' => PaymentStatus::Approved->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        return new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
        ]);
    }

    public function test_an_approved_deposit_reaches_the_staff_channel_as_a_normal_confirmation(): void
    {
        Event::fake([BookingChanged::class]);
        [$booking, $payment] = $this->scenario();

        app(ProcessPaymentWebhook::class)->handle($this->approvedEnvelope($payment, 'evt_realtime_1'));

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);

        Event::assertDispatchedTimes(BookingChanged::class, 1);
        Event::assertDispatched(BookingChanged::class, function (BookingChanged $event) use ($booking) {
            $this->assertSame(BookingChange::Confirmed, $event->change);
            $this->assertSame($booking->id, $event->bookingId);
            $this->assertSame('private-business.'.$booking->business_id, $event->broadcastOn()[0]->name);

            return true;
        });
    }

    public function test_automatic_unpaid_cancellation_reaches_the_staff_channel_the_same_way(): void
    {
        Event::fake([BookingChanged::class]);
        [$booking] = $this->scenario(['payment_expires_at' => now()->subMinute()]);
        $booking->payments()->update(['status' => PaymentStatus::Expired]);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);

        Event::assertDispatchedTimes(BookingChanged::class, 1);
        Event::assertDispatched(
            BookingChanged::class,
            fn (BookingChanged $event) => $event->change === BookingChange::Cancelled
                && $event->bookingId === $booking->id
        );
    }

    public function test_payments_have_no_realtime_class_of_their_own(): void
    {
        // El navegador sólo necesita saber que la RESERVA cambió. Este guard
        // falla si aparece un PaymentApprovedBroadcast, un
        // SimulatedPaymentBroadcast o cualquier evento de WebSocket propio del
        // webhook.
        $classes = array_map('basename', glob(app_path('Events/Broadcasting/*.php')));

        $this->assertSame(['BookingChanged.php'], $classes);
    }
}
