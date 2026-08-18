<?php

namespace Database\Factories;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'simulated',
            'external_event_id' => 'evt_'.Str::ulid(),
            'payment_external_id' => 'sim_pay_'.Str::ulid(),
            'payload' => ['status' => 'approved'],
            'status' => WebhookEventStatus::Received,
            'attempts' => 0,
            'received_at' => now(),
        ];
    }
}
