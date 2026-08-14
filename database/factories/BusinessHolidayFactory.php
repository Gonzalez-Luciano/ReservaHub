<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHoliday>
 */
class BusinessHolidayFactory extends Factory
{
    protected $model = BusinessHoliday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Feriado nacional',
            'starts_on' => '2026-07-09',
            'ends_on' => '2026-07-09',
        ];
    }
}
