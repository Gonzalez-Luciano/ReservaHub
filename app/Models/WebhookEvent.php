<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría e identidad de los eventos entrantes. Tabla global a propósito: un
 * webhook llega sin contexto de negocio, así que no lleva `business_id` ni el
 * scope de negocio. No se expone por API ni por UI.
 */
#[Fillable([
    'provider', 'external_event_id', 'payment_external_id', 'payload', 'status',
    'outcome_reason', 'attempts', 'last_error', 'received_at', 'processed_at',
])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
