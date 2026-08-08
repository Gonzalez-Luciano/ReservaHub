<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'customer_id' => User::factory()->customer(),
            'employee_id' => User::factory()->employee(),
            'service_id' => Service::factory(),
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(10, 30),
            'status' => BookingStatus::Pending,
            'price' => 50,
            'deposit_amount' => null,
            'notes' => null,
            'source' => 'web',
            'cancelled_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::Completed]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::NoShow]);
    }
}
