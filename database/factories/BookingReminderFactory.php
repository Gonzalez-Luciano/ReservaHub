<?php

namespace Database\Factories;

use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\BookingReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingReminder>
 */
class BookingReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'type' => ReminderType::TwentyFourHours,
            'sent_at' => now(),
        ];
    }
}
