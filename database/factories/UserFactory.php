<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Customer,
            'business_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an owner of a new business.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Owner,
            'business_id' => Business::factory(),
        ]);
    }

    /**
     * Indicate that the user is a customer with no business.
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Customer,
            'business_id' => null,
        ]);
    }

    /**
     * Indicate that the user is an employee of a new business.
     */
    public function employee(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Employee,
            'business_id' => Business::factory(),
        ]);
    }
}
