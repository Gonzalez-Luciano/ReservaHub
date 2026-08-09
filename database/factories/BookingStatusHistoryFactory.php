<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingStatusHistory>
 */
class BookingStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'from_status' => null,
            'to_status' => BookingStatus::Pending,
            'changed_by' => User::factory(),
            'notes' => null,
            'created_at' => now(),
        ];
    }
}
