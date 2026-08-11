<?php

namespace Tests\Feature\Notifications;

use App\Actions\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingRescheduledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingRescheduledNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{booking: Booking, newStart: CarbonImmutable}
     */
    private function makeReschedulableBooking(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create([
            'name' => 'Corte de pelo',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
        ]);
        $customer = User::factory()->customer()->create();
        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        $service->employees()->attach($employee->id);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $monday->setTime(9, 0),
            'ends_at' => $monday->setTime(9, 30),
        ]);

        return ['booking' => $booking, 'newStart' => $monday->setTime(10, 0)];
    }

    public function test_rescheduling_notifies_the_customer_and_the_employee(): void
    {
        Notification::fake();
        ['booking' => $booking, 'newStart' => $newStart] = $this->makeReschedulableBooking();
        $previousStart = $booking->starts_at->copy();

        app(RescheduleBooking::class)->handle(
            $booking,
            ['starts_at' => $newStart->toDateTimeString()],
            $booking->customer,
        );

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingRescheduledNotification $notification) => $notification->audience === NotificationAudience::Customer
                && $notification->previousStartsAt->equalTo($previousStart)
                && $notification->booking->starts_at->equalTo($newStart),
        );
        Notification::assertSentTo(
            $booking->employee,
            fn (BookingRescheduledNotification $notification) => $notification->audience === NotificationAudience::Employee,
        );
    }

    public function test_the_status_history_note_keeps_its_format(): void
    {
        Notification::fake();
        ['booking' => $booking, 'newStart' => $newStart] = $this->makeReschedulableBooking();
        $previousStart = $booking->starts_at->copy();

        app(RescheduleBooking::class)->handle(
            $booking,
            ['starts_at' => $newStart->toDateTimeString()],
            $booking->customer,
        );

        $note = $booking->statusHistories()->latest('id')->first()->notes;

        $this->assertSame(
            "Reprogramado de {$previousStart->format('Y-m-d H:i')} a {$newStart->format('Y-m-d H:i')}.",
            $note,
        );
    }

    public function test_the_mail_mentions_both_times(): void
    {
        ['booking' => $booking, 'newStart' => $newStart] = $this->makeReschedulableBooking();
        $previousStart = $booking->starts_at->copy();

        $notification = new BookingRescheduledNotification(
            $booking,
            CarbonImmutable::parse($previousStart),
            NotificationAudience::Customer,
        );
        $mail = $notification->toMail($booking->customer);
        $payload = $notification->toArray($booking->customer);

        $body = implode(' ', $mail->introLines);
        $this->assertStringContainsString('reprogram', mb_strtolower($mail->subject));
        $this->assertStringContainsString($previousStart->format('H:i'), $body);
        $this->assertSame('booking.rescheduled', $payload['type']);
        $this->assertSame($previousStart->toIso8601String(), $payload['previous_starts_at']);
    }
}
