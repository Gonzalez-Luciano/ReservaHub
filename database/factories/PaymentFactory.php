<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            // Se completa en configure(), cuando `booking_id` ya es un id real:
            // resolverlo con una closure de atributos rompe si la factory se usa
            // suelta, porque ahí `booking_id` todavía es una Factory.
            'business_id' => null,
            'provider' => 'simulated',
            'external_id' => 'sim_pay_'.Str::ulid(),
            'status' => PaymentStatus::Pending,
            'amount' => '10.00',
            'currency' => 'ARS',
            'expires_at' => now()->addMinutes(30),
            'last_snapshot' => ['status' => 'pending'],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Payment $payment) {
            $payment->business_id ??= Booking::withoutGlobalScopes()
                ->find($payment->booking_id)?->business_id;
        });
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Approved,
            'paid_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PaymentStatus::Rejected]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
