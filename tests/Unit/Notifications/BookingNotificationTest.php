<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class BookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(string $timezone = 'America/Argentina/Buenos_Aires'): Booking
    {
        $business = Business::factory()->create(['timezone' => $timezone]);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create(['name' => 'Ana'])->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id, 'name' => 'Beto'])->id,
            'starts_at' => '2026-08-12 17:30:00',
        ]);
    }

    public function test_it_is_queued_and_uses_mail_and_database(): void
    {
        $notification = new BookingNotificationStub($this->makeBooking());

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['mail', 'database'], $notification->via(new User));
    }

    public function test_it_formats_the_start_time_in_the_business_timezone_in_spanish(): void
    {
        $notification = new BookingNotificationStub($this->makeBooking());

        // 2026-08-12 17:30 UTC es 14:30 en Buenos Aires, y ese día es miércoles.
        $this->assertSame('mié. 12 ago. 2026, 14:30', $notification->exposedFormatDateTime());
    }

    public function test_it_resolves_the_service_even_when_another_business_is_bound(): void
    {
        $booking = $this->makeBooking();
        app()->instance(Business::class, Business::factory()->create());

        $notification = new BookingNotificationStub($booking);

        $this->assertSame('Corte de pelo', $notification->exposedService()->name);
    }

    public function test_the_action_url_depends_on_the_audience(): void
    {
        $booking = $this->makeBooking();
        $notification = new BookingNotificationStub($booking);

        $this->assertSame(
            route('public.bookings.mine.index'),
            $notification->exposedActionUrl(NotificationAudience::Customer),
        );
        $this->assertSame(
            route('dashboard.bookings.show', $booking),
            $notification->exposedActionUrl(NotificationAudience::Employee),
        );
    }

    public function test_the_base_payload_carries_the_booking_context(): void
    {
        $booking = $this->makeBooking();

        $payload = (new BookingNotificationStub($booking))->exposedBasePayload();

        $this->assertSame($booking->id, $payload['booking_id']);
        $this->assertSame($booking->business_id, $payload['business_id']);
        $this->assertSame('Corte de pelo', $payload['service']);
        $this->assertSame('Ana', $payload['customer']);
        $this->assertSame('Beto', $payload['employee']);
    }
}

class BookingNotificationStub extends BookingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return $this->basePayload();
    }

    public function exposedFormatDateTime(): string
    {
        return $this->formatDateTime();
    }

    public function exposedService(): Service
    {
        return $this->service();
    }

    public function exposedActionUrl(NotificationAudience $audience): string
    {
        return $this->actionUrl($audience);
    }

    /** @return array<string, mixed> */
    public function exposedBasePayload(): array
    {
        return $this->basePayload();
    }
}
