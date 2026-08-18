<?php

namespace Tests\Feature\Payments;

use App\Actions\Bookings\CancelBooking;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\NotificationAudience;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class ExpireUnpaidBookingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function booking(array $overrides = [], int $cancellationHours = 24): Booking
    {
        $business = Business::factory()->create([
            'timezone' => 'UTC',
            'currency' => 'ARS',
            'cancellation_hours' => $cancellationHours,
        ]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => '10.00']);

        return Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'payment_expires_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_it_cancels_a_booking_with_no_payment_attempt(): void
    {
        Notification::fake();
        $booking = $this->booking();

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);

        $history = $booking->statusHistories()->latest('id')->first();
        $this->assertNull($history->changed_by);
        $this->assertStringContainsString('seña', $history->notes);

        Notification::assertSentToTimes($booking->customer, BookingCancelledNotification::class, 1);
    }

    public function test_it_cancels_when_every_attempt_is_terminal_and_unpaid(): void
    {
        $booking = $this->booking();
        Payment::factory()->for($booking)->create(['business_id' => $booking->business_id, 'status' => PaymentStatus::Rejected]);
        Payment::factory()->for($booking)->create(['business_id' => $booking->business_id, 'status' => PaymentStatus::Expired]);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }

    public function test_it_never_cancels_while_an_attempt_is_still_pending(): void
    {
        $booking = $this->booking();
        Payment::factory()->for($booking)->create(['business_id' => $booking->business_id, 'status' => PaymentStatus::Pending]);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_it_never_cancels_when_an_approved_payment_exists(): void
    {
        $booking = $this->booking();
        Payment::factory()->for($booking)->approved()->create(['business_id' => $booking->business_id]);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_it_ignores_bookings_whose_window_is_still_open_or_absent(): void
    {
        $open = $this->booking(['payment_expires_at' => now()->addMinutes(10)]);
        $legacy = $this->booking(['payment_expires_at' => null]);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Pending, $open->refresh()->status);
        $this->assertSame(BookingStatus::Pending, $legacy->refresh()->status);
    }

    public function test_it_ignores_bookings_that_are_no_longer_pending(): void
    {
        $confirmed = $this->booking(['status' => BookingStatus::Confirmed]);
        $cancelled = $this->booking(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()->subHour()]);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Confirmed, $confirmed->refresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $cancelled->refresh()->status);
    }

    public function test_it_cancels_even_inside_the_customer_cancellation_cutoff(): void
    {
        Notification::fake();
        // El turno empieza en 3 horas y el negocio exige 24: un cliente ya no
        // podría cancelar. La expiración del sistema sí puede.
        $booking = $this->booking([
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(3)->addMinutes(30),
        ], cancellationHours: 24);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }

    public function test_a_customer_still_cannot_cancel_inside_the_cutoff(): void
    {
        $booking = $this->booking([
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(3)->addMinutes(30),
            'payment_expires_at' => now()->addMinutes(10),
        ], cancellationHours: 24);

        $this->expectException(ValidationException::class);

        app(CancelBooking::class)->handle($booking, $booking->customer);
    }

    public function test_cancellation_contexts_are_validated(): void
    {
        $booking = $this->booking();

        try {
            app(CancelBooking::class)->handle($booking, null);
            $this->fail('Se esperaba InvalidArgumentException por cancelación manual sin actor.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            app(CancelBooking::class)->handle($booking, $booking->customer, CancellationReason::PaymentWindowExpired);
            $this->fail('Se esperaba InvalidArgumentException por cancelación de sistema con actor.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_the_system_cancellation_email_explains_the_reason(): void
    {
        $booking = $this->booking();

        $notification = new BookingCancelledNotification(
            $booking,
            null,
            NotificationAudience::Customer,
            CancellationReason::PaymentWindowExpired,
        );

        $mail = $notification->toMail($booking->customer);

        $this->assertStringContainsString('seña', implode(' ', $mail->introLines));
    }
}
