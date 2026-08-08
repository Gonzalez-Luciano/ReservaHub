<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingStatus;
use Tests\TestCase;

class BookingStatusTest extends TestCase
{
    public function test_has_expected_cases_and_values(): void
    {
        $this->assertSame('pending', BookingStatus::Pending->value);
        $this->assertSame('confirmed', BookingStatus::Confirmed->value);
        $this->assertSame('cancelled', BookingStatus::Cancelled->value);
        $this->assertSame('completed', BookingStatus::Completed->value);
        $this->assertSame('no_show', BookingStatus::NoShow->value);
    }
}
