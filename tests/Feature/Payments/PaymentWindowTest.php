<?php

namespace Tests\Feature\Payments;

use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Support\PaymentWindowBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Laravel's own test harness (travelTo()/InteractsWithTime) only ever
        // freezes Illuminate\Support\Carbon — it never touches
        // Carbon\CarbonImmutable, which has its own independent test-now
        // state and is what the application actually uses throughout. Any
        // test below that freezes CarbonImmutable must be unfrozen here, or
        // the override leaks into every later test in the same process.
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{0: Business, 1: User, 2: User, 3: Service}
     */
    private function scenario(?string $depositAmount = '10.00', int $durationMinutes = 30): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create([
            'duration_minutes' => $durationMinutes,
            'buffer_minutes' => 0,
            'deposit_amount' => $depositAmount,
        ]);
        $service->employees()->attach($employee->id);

        foreach (DayOfWeek::cases() as $day) {
            Schedule::factory()->create([
                'business_id' => $business->id,
                'employee_id' => $employee->id,
                'day_of_week' => $day,
                'start_time' => '00:00',
                'end_time' => '23:30',
                'is_active' => true,
            ]);
        }

        return [$business, $employee, $customer, $service];
    }

    private function create(Business $business, User $employee, User $customer, Service $service, CarbonImmutable $startsAt): Booking
    {
        return app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);
    }

    /**
     * AvailabilityService only ever offers slots on a fixed grid anchored to
     * local midnight and stepped by the service's duration — never on
     * arbitrary "now + N minutes" instants. Near-term tests need a real,
     * bookable slot, so this always rounds up to the next grid boundary
     * strictly after `$from` (never `$from` itself, so a few milliseconds of
     * test setup between capturing "now" and the actual booking call can
     * never push the slot into the past).
     */
    private function nextSlot(CarbonImmutable $from, int $stepMinutes): CarbonImmutable
    {
        $minutesSinceMidnight = $from->hour * 60 + $from->minute;
        $step = intdiv($minutesSinceMidnight, $stepMinutes) + 1;

        return $from->startOfDay()->addMinutes($step * $stepMinutes);
    }

    public function test_a_deposit_booking_gets_a_payment_window(): void
    {
        [$business, $employee, $customer, $service] = $this->scenario();
        $startsAt = CarbonImmutable::now('UTC')->addDays(2)->setTime(10, 0);

        $booking = $this->create($business, $employee, $customer, $service, $startsAt);

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNotNull($booking->payment_expires_at);
        $this->assertEqualsWithDelta(
            CarbonImmutable::now()->addMinutes(30)->getTimestamp(),
            $booking->payment_expires_at->getTimestamp(),
            5,
        );
    }

    public function test_the_window_never_outlives_the_appointment(): void
    {
        [$business, $employee, $customer, $service] = $this->scenario();
        // Freeze CarbonImmutable's clock to a safe mid-day instant. Without
        // this, `nextSlot()`'s grid math has one genuinely invalid mark each
        // day: the schedule (see scenario()) closes at 23:30, and a 30-minute
        // service starting exactly at 23:30 would end at 24:00 — past
        // closing — so AvailabilityService::generateCandidates() never
        // produces it. Whenever real "now" falls between 23:00 and 23:30,
        // `nextSlot(now, 30)` lands exactly on that one bad mark and
        // CreateBooking deterministically rejects it as unavailable — not a
        // timing race, a real once-a-day 30-minute dead zone. Freezing to a fixed off-grid instant
        // (10:07) the same day sidesteps it entirely, and also removes any
        // residual clock drift between this computation and CreateBooking's
        // own internal re-check.
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->startOfDay()->addHours(10)->addMinutes(7));

        // The next bookable grid slot is always <= 30 minutes out, so the
        // deposit window (30 min, per the config default) would otherwise
        // outlive it — this is exactly the clamp scenario under test.
        $startsAt = $this->nextSlot(CarbonImmutable::now('UTC'), 30);

        $booking = $this->create($business, $employee, $customer, $service, $startsAt);

        $this->assertSame($startsAt->getTimestamp(), $booking->payment_expires_at->getTimestamp());
    }

    public function test_a_booking_without_deposit_has_no_window(): void
    {
        [$business, $employee, $customer, $service] = $this->scenario(depositAmount: null);
        $startsAt = CarbonImmutable::now('UTC')->addDays(2)->setTime(11, 0);

        $booking = $this->create($business, $employee, $customer, $service, $startsAt);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNull($booking->payment_expires_at);
    }

    public function test_rescheduling_earlier_shortens_the_window_and_later_never_extends_it(): void
    {
        // A finer slot grid (10-minute steps, vs. the 30-minute default) is
        // needed here: the test requires two distinct real, bookable, future
        // slots inside the 30-minute deposit window — one for the original
        // booking and a strictly earlier one to reschedule into.
        [$business, $employee, $customer, $service] = $this->scenario(durationMinutes: 10);
        // Freeze the clock — see test_the_window_never_outlives_the_appointment's
        // comment for why: the schedule's 23:30 close creates one genuine
        // dead grid mark per day, and this test does even more real DB work
        // (a full booking creation plus two reschedules) than that one
        // between computing $earlier and the last point it gets re-checked.
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->startOfDay()->addHours(10)->addMinutes(7));
        $now = CarbonImmutable::now('UTC');
        $earlier = $this->nextSlot($now, 10);
        $startsAt = $earlier->addMinutes(10);

        $booking = $this->create($business, $employee, $customer, $service, $startsAt);
        $this->assertSame($startsAt->getTimestamp(), $booking->payment_expires_at->getTimestamp());

        $booking = app(RescheduleBooking::class)->handle($booking, ['starts_at' => $earlier->toIso8601String()], $customer);
        $this->assertSame($earlier->getTimestamp(), $booking->payment_expires_at->getTimestamp());

        $later = CarbonImmutable::now('UTC')->addDays(3)->setTime(9, 0);
        $booking = app(RescheduleBooking::class)->handle($booking, ['starts_at' => $later->toIso8601String()], $customer);
        $this->assertSame($earlier->getTimestamp(), $booking->payment_expires_at->getTimestamp());
    }

    public function test_rescheduling_before_a_live_attempt_expiry_is_rejected(): void
    {
        [$business, $employee, $customer, $service] = $this->scenario();
        // Freeze the clock — see test_the_window_never_outlives_the_appointment's
        // comment: the schedule's 23:30 close creates one genuine dead grid
        // mark per day that nextSlot() can land on deterministically.
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->startOfDay()->addHours(10)->addMinutes(7));
        $now = CarbonImmutable::now('UTC');
        $startsAt = $now->addDays(2)->setTime(10, 0);
        $booking = $this->create($business, $employee, $customer, $service, $startsAt);

        Payment::factory()->for($booking)->create([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'expires_at' => $booking->payment_expires_at,
        ]);

        // Real, bookable near-term slot, earlier than the live payment's
        // deadline (booking creation happens after $now, so its 30-minute
        // window necessarily ends after this grid slot does).
        $earlier = $this->nextSlot($now, 30);

        try {
            app(RescheduleBooking::class)->handle($booking, ['starts_at' => $earlier->toIso8601String()], $customer);
            $this->fail('Se esperaba ValidationException por pago de seña en curso.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('starts_at', $e->errors());
        }

        $this->assertSame($startsAt->getTimestamp(), $booking->refresh()->starts_at->getTimestamp());
    }

    public function test_rescheduling_after_a_live_attempt_expiry_is_allowed(): void
    {
        [$business, $employee, $customer, $service] = $this->scenario();
        $startsAt = CarbonImmutable::now('UTC')->addDays(2)->setTime(10, 0);
        $booking = $this->create($business, $employee, $customer, $service, $startsAt);

        Payment::factory()->for($booking)->create([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'expires_at' => $booking->payment_expires_at,
        ]);

        $later = CarbonImmutable::now('UTC')->addDays(3)->setTime(9, 0);
        $booking = app(RescheduleBooking::class)->handle($booking, ['starts_at' => $later->toIso8601String()], $customer);

        $this->assertSame($later->getTimestamp(), $booking->starts_at->getTimestamp());
    }

    public function test_rescheduling_locks_the_booking_row_before_deciding_about_a_live_payment(): void
    {
        // InitiatePayment serializes on `Booking::withoutGlobalScopes()->
        // lockForUpdate()->findOrFail(...)` before creating a payment.
        // RescheduleBooking's contradictory-window guard only holds if it
        // serializes against the *same* row lock — otherwise a reschedule
        // reading "no live payment" and a concurrent InitiatePayment can both
        // proceed. A genuine two-session interleaving belongs in
        // WebhookConcurrencyTest; here it's enough to prove the lock
        // statement is actually issued, by listening for a `for update`
        // query against `bookings` during the transaction.
        [$business, $employee, $customer, $service] = $this->scenario();
        $startsAt = CarbonImmutable::now('UTC')->addDays(2)->setTime(10, 0);
        $booking = $this->create($business, $employee, $customer, $service, $startsAt);
        $later = CarbonImmutable::now('UTC')->addDays(3)->setTime(9, 0);

        $lockingQueries = [];
        DB::listen(function ($query) use (&$lockingQueries) {
            if (str_contains($query->sql, 'bookings') && str_contains(strtolower($query->sql), 'for update')) {
                $lockingQueries[] = $query->sql;
            }
        });

        app(RescheduleBooking::class)->handle($booking, ['starts_at' => $later->toIso8601String()], $customer);

        $this->assertNotEmpty(
            $lockingQueries,
            'RescheduleBooking debe adquirir un lock de fila sobre bookings, con el mismo límite que InitiatePayment, antes de decidir sobre el pago vivo.',
        );
    }

    public function test_the_backfill_only_covers_future_legacy_bookings(): void
    {
        [$business, $employee, $customer, $service] = $this->scenario();

        $future = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addMinutes(30),
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
        ]);

        $past = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addMinutes(30),
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
        ]);

        $withoutDeposit = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addMinutes(30),
            'status' => BookingStatus::Pending,
            'deposit_amount' => null,
        ]);

        DB::table('bookings')->update(['payment_expires_at' => null]);

        $updated = (new PaymentWindowBackfill)->run();

        $this->assertSame(1, $updated);
        $this->assertNotNull($future->refresh()->payment_expires_at);
        $this->assertTrue($future->payment_expires_at->isFuture());
        $this->assertNull($past->refresh()->payment_expires_at);
        $this->assertNull($withoutDeposit->refresh()->payment_expires_at);
    }
}
