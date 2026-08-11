<?php

namespace Tests\Feature\Notifications;

use App\Actions\Bookings\CancelBooking;
use App\Enums\BookingStatus;
use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingCancelledNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfirmedBooking(): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'cancellation_hours' => 2]);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create(['name' => 'Ana'])->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
        ]);
    }

    public function test_cancelling_notifies_the_customer_and_the_employee(): void
    {
        Notification::fake();
        $booking = $this->makeConfirmedBooking();

        app(CancelBooking::class)->handle($booking, $booking->customer);

        Notification::assertSentTo($booking->customer, BookingCancelledNotification::class);
        Notification::assertSentTo($booking->employee, BookingCancelledNotification::class);
    }

    public function test_the_event_carries_the_already_cancelled_booking(): void
    {
        Notification::fake();
        $booking = $this->makeConfirmedBooking();

        app(CancelBooking::class)->handle($booking, $booking->customer);

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingCancelledNotification $notification) => $notification->booking->status === BookingStatus::Cancelled,
        );
    }

    public function test_the_customer_mail_changes_when_the_business_cancels(): void
    {
        $booking = $this->makeConfirmedBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        $byCustomer = (new BookingCancelledNotification($booking, $booking->customer, NotificationAudience::Customer))
            ->toMail($booking->customer);
        $byBusiness = (new BookingCancelledNotification($booking, $owner, NotificationAudience::Customer))
            ->toMail($booking->customer);

        $this->assertStringContainsString('Cancelaste', implode(' ', $byCustomer->introLines));
        $this->assertStringContainsString('canceló tu reserva', implode(' ', $byBusiness->introLines));
    }

    public function test_the_payload_records_who_cancelled(): void
    {
        $booking = $this->makeConfirmedBooking();

        $payload = (new BookingCancelledNotification($booking, $booking->customer, NotificationAudience::Employee))
            ->toArray($booking->employee);

        $this->assertSame('booking.cancelled', $payload['type']);
        $this->assertSame($booking->customer_id, $payload['cancelled_by']);
        $this->assertTrue($payload['cancelled_by_customer']);
    }
}
