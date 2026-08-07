<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->randomElement(['Corte de cabello', 'Coloración', 'Manicura', 'Masaje', 'Depilación']),
            'description' => fake()->sentence(),
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90]),
            'buffer_minutes' => 10,
            'price' => fake()->randomFloat(2, 10, 200),
            'deposit_amount' => null,
            'is_active' => true,
        ];
    }
}
