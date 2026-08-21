<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingChange;
use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BookingChangeTest extends TestCase
{
    public function test_it_exposes_exactly_the_six_approved_values(): void
    {
        $this->assertSame(
            ['created', 'confirmed', 'cancelled', 'rescheduled', 'completed', 'no_show'],
            array_map(fn (BookingChange $case) => $case->value, BookingChange::cases())
        );
    }

    public function test_it_maps_every_booking_domain_event(): void
    {
        $booking = new Booking;

        $cases = [
            [new BookingCreated($booking), BookingChange::Created],
            [new BookingConfirmed($booking), BookingChange::Confirmed],
            [new BookingCancelled($booking, null), BookingChange::Cancelled],
            [new BookingRescheduled($booking, CarbonImmutable::parse('2026-08-20 10:00')), BookingChange::Rescheduled],
            [new BookingCompleted($booking), BookingChange::Completed],
            [new BookingNoShow($booking), BookingChange::NoShow],
        ];

        foreach ($cases as [$event, $expected]) {
            $this->assertSame($expected, BookingChange::forEvent($event), $event::class);
        }
    }

    public function test_it_refuses_an_unmapped_event(): void
    {
        $this->expectException(\UnhandledMatchError::class);

        BookingChange::forEvent(new \stdClass);
    }
}
