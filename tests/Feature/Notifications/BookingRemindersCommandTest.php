<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(string $startsIn, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => $status,
            'starts_at' => now()->add($startsIn),
            'ends_at' => now()->add($startsIn)->addMinutes(30),
        ]);
    }

    public function test_it_sends_the_24h_reminder_inside_the_window(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('23 hours');

        $this->artisan('bookings:send-reminders')->assertExitCode(0);

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingReminderNotification $notification) => $notification->type === ReminderType::TwentyFourHours,
        );
        $this->assertDatabaseHas('booking_reminders', [
            'booking_id' => $booking->id,
            'type' => '24h',
        ]);
    }

    public function test_it_does_not_send_the_24h_reminder_before_the_window(): void
    {
        Notification::fake();
        $this->makeBooking('30 hours');

        $this->artisan('bookings:send-reminders');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('booking_reminders', 0);
    }

    public function test_a_booking_within_two_hours_only_gets_the_2h_reminder(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('90 minutes');

        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 1);
        $this->assertDatabaseHas('booking_reminders', ['booking_id' => $booking->id, 'type' => '2h']);
        $this->assertDatabaseMissing('booking_reminders', ['booking_id' => $booking->id, 'type' => '24h']);
    }

    public function test_running_twice_sends_each_reminder_only_once(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('23 hours');

        $this->artisan('bookings:send-reminders');
        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 1);
        $this->assertDatabaseCount('booking_reminders', 1);
    }

    public function test_a_booking_gets_both_reminders_as_time_passes(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('23 hours');

        $this->artisan('bookings:send-reminders');
        $this->travel(22)->hours();
        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 2);
        $this->assertDatabaseCount('booking_reminders', 2);
    }

    public function test_it_skips_bookings_that_are_not_confirmed(): void
    {
        Notification::fake();
        $this->makeBooking('23 hours', BookingStatus::Pending);
        $this->makeBooking('23 hours', BookingStatus::Cancelled);
        $this->makeBooking('23 hours', BookingStatus::Completed);
        $this->makeBooking('23 hours', BookingStatus::NoShow);

        $this->artisan('bookings:send-reminders');

        Notification::assertNothingSent();
    }

    public function test_it_skips_bookings_that_already_started(): void
    {
        Notification::fake();
        $this->makeBooking('-1 hour');

        $this->artisan('bookings:send-reminders');

        Notification::assertNothingSent();
    }

    public function test_it_catches_up_on_a_reminder_whose_window_was_missed(): void
    {
        Notification::fake();
        // El turno está a 3 horas: la ventana de 24 h ya pasó sin que el comando corriera.
        $booking = $this->makeBooking('3 hours');

        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 1);
        $this->assertDatabaseHas('booking_reminders', ['booking_id' => $booking->id, 'type' => '24h']);
    }

    public function test_it_covers_bookings_from_every_business_in_one_run(): void
    {
        Notification::fake();
        $first = $this->makeBooking('23 hours');
        $second = $this->makeBooking('23 hours');

        $this->assertNotSame($first->business_id, $second->business_id);

        $this->artisan('bookings:send-reminders');

        Notification::assertSentTo($first->customer, BookingReminderNotification::class);
        Notification::assertSentTo($second->customer, BookingReminderNotification::class);
    }

    public function test_the_reminder_mail_and_payload_name_the_window(): void
    {
        $booking = $this->makeBooking('23 hours');
        $notification = new BookingReminderNotification($booking, ReminderType::TwoHours);

        $mail = $notification->toMail($booking->customer);
        $payload = $notification->toArray($booking->customer);

        $this->assertStringContainsString('2 horas', implode(' ', $mail->introLines));
        $this->assertSame('booking.reminder', $payload['type']);
        $this->assertSame('2h', $payload['reminder']);
    }
}
