<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeOff>
 */
class TimeOffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'employee_id' => User::factory()->employee(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addDays(2),
            'reason' => 'Vacaciones',
        ];
    }
}
