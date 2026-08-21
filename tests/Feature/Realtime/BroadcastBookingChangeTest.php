<?php

namespace Tests\Feature\Realtime;

use App\Actions\Bookings\CompleteBooking;
use App\Enums\BookingChange;
use App\Enums\Role;
use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;
use App\Events\Broadcasting\BookingChanged;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BroadcastBookingChangeTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $overrides = []): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create([
            'name' => 'Cliente Secreto',
            'email' => 'secreto@example.com',
        ]);
        $service = Service::factory()->for($business)->create();

        return Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'notes' => 'nota interna reservada',
            'price' => '1234.56',
        ], $overrides));
    }

    /**
     * @return array<string, array{0: string, 1: BookingChange}>
     */
    public static function transitions(): array
    {
        return [
            'created' => [BookingCreated::class, BookingChange::Created],
            'confirmed' => [BookingConfirmed::class, BookingChange::Confirmed],
            'cancelled' => [BookingCancelled::class, BookingChange::Cancelled],
            'rescheduled' => [BookingRescheduled::class, BookingChange::Rescheduled],
            'completed' => [BookingCompleted::class, BookingChange::Completed],
            'no_show' => [BookingNoShow::class, BookingChange::NoShow],
        ];
    }

    #[DataProvider('transitions')]
    public function test_every_domain_transition_produces_exactly_one_safe_broadcast(
        string $domainEvent,
        BookingChange $expected,
    ): void {
        Event::fake([BookingChanged::class]);
        $booking = $this->booking();

        event(match ($domainEvent) {
            BookingCancelled::class => new BookingCancelled($booking, null),
            BookingRescheduled::class => new BookingRescheduled($booking, CarbonImmutable::parse('2026-08-20 10:00')),
            default => new $domainEvent($booking),
        });

        Event::assertDispatchedTimes(BookingChanged::class, 1);
        Event::assertDispatched(BookingChanged::class, function (BookingChanged $event) use ($booking, $expected) {
            $this->assertSame($booking->business_id, $event->businessId);
            $this->assertSame($expected, $event->change);
            $this->assertSame(
                'private-business.'.$booking->business_id,
                $event->broadcastOn()[0]->name
            );
            $this->assertSame('booking.changed', $event->broadcastAs());
            $this->assertSame([
                'booking_id' => $booking->id,
                'change' => $expected->value,
                'updated_at' => $booking->updated_at->toIso8601String(),
            ], $event->broadcastWith());

            return true;
        });
    }

    public function test_the_payload_carries_no_customer_or_money_data(): void
    {
        Event::fake([BookingChanged::class]);
        $booking = $this->booking();

        event(new BookingConfirmed($booking));

        Event::assertDispatched(BookingChanged::class, function (BookingChanged $event) {
            // Esto inspecciona el array que devuelve broadcastWith() — el payload
            // que el broadcaster enviaría —, no un frame real de WebSocket.
            $encoded = json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR);

            foreach (['Cliente Secreto', 'secreto@example.com', 'nota interna reservada', '1234.56'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }

            $this->assertSame(['booking_id', 'change', 'updated_at'], array_keys($event->broadcastWith()));

            return true;
        });
    }

    public function test_nothing_is_broadcast_when_the_surrounding_transaction_rolls_back(): void
    {
        Event::fake([BookingChanged::class]);
        $business = Business::factory()->create();
        $staff = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        try {
            DB::transaction(function () use ($booking, $staff) {
                app(CompleteBooking::class)->handle($booking, $staff);

                throw new RuntimeException('la transacción se cae después de la transición');
            });
        } catch (RuntimeException) {
            // esperado
        }

        Event::assertNotDispatched(BookingChanged::class);
    }

    public function test_it_broadcasts_once_the_surrounding_transaction_commits(): void
    {
        Event::fake([BookingChanged::class]);
        $business = Business::factory()->create();
        $staff = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        DB::transaction(fn () => app(CompleteBooking::class)->handle($booking, $staff));

        Event::assertDispatchedTimes(BookingChanged::class, 1);
    }
}
