<?php

namespace Tests\Feature\Notifications;

use App\Actions\Bookings\ConfirmBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingConfirmedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingConfirmedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingBooking(): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => BookingStatus::Pending,
        ]);
    }

    public function test_confirming_notifies_the_customer(): void
    {
        Notification::fake();
        $booking = $this->makePendingBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        app(ConfirmBooking::class)->handle($booking, $owner);

        Notification::assertSentTo($booking->customer, BookingConfirmedNotification::class);
    }

    public function test_confirming_does_not_notify_the_employee(): void
    {
        Notification::fake();
        $booking = $this->makePendingBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        app(ConfirmBooking::class)->handle($booking, $owner);

        Notification::assertNotSentTo($booking->employee, BookingConfirmedNotification::class);
    }

    public function test_the_event_carries_the_already_confirmed_booking(): void
    {
        Notification::fake();
        $booking = $this->makePendingBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        app(ConfirmBooking::class)->handle($booking, $owner);

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingConfirmedNotification $notification) => $notification->booking->status === BookingStatus::Confirmed,
        );
    }

    public function test_the_mail_and_payload_describe_the_confirmation(): void
    {
        $booking = $this->makePendingBooking();
        $booking->update(['status' => BookingStatus::Confirmed]);
        $notification = new BookingConfirmedNotification($booking->fresh());

        $mail = $notification->toMail($booking->customer);
        $payload = $notification->toArray($booking->customer);

        $this->assertStringContainsString('confirmada', $mail->subject);
        $this->assertSame('booking.confirmed', $payload['type']);
        $this->assertSame($booking->id, $payload['booking_id']);
    }
}
