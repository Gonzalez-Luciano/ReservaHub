<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\NotificationAudience;
use App\Events\BookingCreated;
use App\Listeners\SendBookingCreatedNotifications;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingCreatedNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingCreatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => $status,
        ]);
    }

    public function test_it_notifies_the_customer_and_the_employee(): void
    {
        Notification::fake();
        $booking = $this->makeBooking();

        event(new BookingCreated($booking));

        Notification::assertSentTo(
            $booking->customer,
            BookingCreatedNotification::class,
            fn (BookingCreatedNotification $notification) => $notification->audience === NotificationAudience::Customer
                && $notification->booking->is($booking),
        );
        Notification::assertSentTo(
            $booking->employee,
            BookingCreatedNotification::class,
            fn (BookingCreatedNotification $notification) => $notification->audience === NotificationAudience::Employee,
        );
    }

    public function test_the_customer_mail_confirms_when_the_booking_needs_no_deposit(): void
    {
        $booking = $this->makeBooking(BookingStatus::Confirmed);

        $mail = (new BookingCreatedNotification($booking, NotificationAudience::Customer))
            ->toMail($booking->customer);

        $this->assertStringContainsString('confirmada', $mail->subject);
        $this->assertStringNotContainsString('seña', implode(' ', $mail->introLines));
    }

    public function test_the_customer_mail_asks_for_the_deposit_when_the_booking_is_pending(): void
    {
        $booking = $this->makeBooking(BookingStatus::Pending);
        $booking->update(['deposit_amount' => 1500]);

        $mail = (new BookingCreatedNotification($booking->fresh(), NotificationAudience::Customer))
            ->toMail($booking->customer);

        $this->assertStringContainsString('pendiente', $mail->subject);
        $this->assertStringContainsString('seña', implode(' ', $mail->introLines));
    }

    public function test_the_database_payload_records_the_status(): void
    {
        $booking = $this->makeBooking(BookingStatus::Confirmed);

        $payload = (new BookingCreatedNotification($booking, NotificationAudience::Employee))
            ->toArray($booking->employee);

        $this->assertSame($booking->id, $payload['booking_id']);
        $this->assertSame('booking.created', $payload['type']);
        $this->assertSame('confirmed', $payload['status']);
    }

    public function test_the_notification_is_persisted_on_the_database_channel(): void
    {
        $booking = $this->makeBooking();

        event(new BookingCreated($booking));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $booking->customer_id,
            'notifiable_type' => User::class,
            'type' => BookingCreatedNotification::class,
        ]);
    }

    public function test_the_listener_is_queued_instead_of_running_inline(): void
    {
        Queue::fake();
        $booking = $this->makeBooking();

        event(new BookingCreated($booking));

        Queue::assertPushed(
            CallQueuedListener::class,
            fn (CallQueuedListener $job) => $job->class === SendBookingCreatedNotifications::class,
        );
        $this->assertDatabaseCount('notifications', 0);
    }
}
