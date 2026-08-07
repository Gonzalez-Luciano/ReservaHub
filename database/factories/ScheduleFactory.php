<?php

namespace Database\Factories;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'employee_id' => User::factory()->employee(),
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ];
    }
}
