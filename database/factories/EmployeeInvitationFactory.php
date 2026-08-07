<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeInvitation>
 */
class EmployeeInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'token' => Str::random(40),
            'invited_by_id' => User::factory()->owner(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()]);
    }
}
