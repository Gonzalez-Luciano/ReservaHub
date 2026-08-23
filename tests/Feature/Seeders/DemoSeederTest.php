<?php

namespace Tests\Feature\Seeders;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Named `seedDataset()`, not `seed()`: `Illuminate\Foundation\Testing\TestCase`
     * already declares a public `seed()` (via `InteractsWithDatabase`), and PHP
     * forbids narrowing an inherited public method to `private` — that shape
     * is a fatal error, not a test failure.
     */
    private function seedDataset(): void
    {
        $this->seed(DemoSeeder::class);
    }

    public function test_it_seeds_two_businesses_and_is_idempotent(): void
    {
        $this->seedDataset();
        $this->seedDataset();

        $this->assertSame(2, Business::count());
        $this->assertSame(23, Booking::withoutGlobalScope(BusinessScope::class)->count());
    }

    public function test_no_seeded_booking_or_payment_is_pending(): void
    {
        $this->seedDataset();

        $this->assertSame(
            0,
            Booking::withoutGlobalScope(BusinessScope::class)->where('status', BookingStatus::Pending)->count(),
            'Una reserva pendiente sembrada se cancelaría sola minutos después del reinicio.'
        );

        $this->assertSame(
            0,
            Payment::withoutGlobalScope(BusinessScope::class)->where('status', PaymentStatus::Pending)->count()
        );
    }

    public function test_the_four_stable_states_are_present(): void
    {
        $this->seedDataset();

        foreach ([BookingStatus::Confirmed, BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::NoShow] as $status) {
            $this->assertTrue(
                Booking::withoutGlobalScope(BusinessScope::class)->where('status', $status)->exists(),
                "Falta el estado {$status->value} en el dataset sembrado."
            );
        }
    }

    public function test_exactly_two_services_require_a_deposit(): void
    {
        $this->seedDataset();

        $withDeposit = Service::withoutGlobalScope(BusinessScope::class)->whereNotNull('deposit_amount')->pluck('name')->sort()->values()->all();

        $this->assertSame(['Coloración', 'Grabación de demo'], $withDeposit);
    }

    public function test_every_seeded_payment_has_a_matching_provider_row(): void
    {
        $this->seedDataset();

        $payments = Payment::withoutGlobalScope(BusinessScope::class)->get();

        $this->assertCount(3, $payments);

        foreach ($payments as $payment) {
            $this->assertDatabaseHas('simulated_provider_payments', ['external_id' => $payment->external_id]);
        }
    }

    public function test_seeding_sends_no_mail(): void
    {
        Notification::fake();

        $this->seedDataset();

        Notification::assertNothingSent();
    }

    public function test_todays_bookings_fall_inside_their_employee_schedule_and_never_overlap(): void
    {
        $this->seedDataset();

        $business = Business::where('slug', 'peluqueria-demo')->firstOrFail();
        $today = CarbonImmutable::now($business->timezone)->startOfDay();

        $bookings = Booking::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->whereBetween('starts_at', [$today->utc(), $today->addDay()->utc()])
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(6, $bookings);

        $previousEnd = null;

        foreach ($bookings as $booking) {
            $start = $booking->starts_at->setTimezone($business->timezone);
            $end = $booking->ends_at->setTimezone($business->timezone);

            $this->assertGreaterThanOrEqual(9, $start->hour, 'Una reserva arranca antes de las 09:00.');
            $this->assertLessThanOrEqual(18 * 60, $end->hour * 60 + $end->minute, 'Una reserva termina después de las 18:00.');

            if ($previousEnd !== null) {
                $this->assertTrue($start->greaterThanOrEqualTo($previousEnd), 'Dos reservas de hoy se superponen; el riel es de una sola columna.');
            }

            $previousEnd = $end;
        }
    }
}
