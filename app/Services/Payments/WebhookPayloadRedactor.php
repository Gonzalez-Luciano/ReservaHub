<?php

namespace App\Services\Payments;

/**
 * Lista blanca, no lista negra: lo que no está explícitamente permitido no se
 * persiste. Así ningún campo nuevo del proveedor puede filtrar datos personales
 * o credenciales a la base por omisión.
 */
class WebhookPayloadRedactor
{
    private const ALLOWED = [
        'event_id',
        'payment_id',
        'status',
        'amount',
        'currency',
        'occurred_at',
        'reference',
        'failure_reason',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $redacted = [];

        foreach (self::ALLOWED as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            // Solo escalares o null: una estructura anidada bajo una clave
            // permitida podría arrastrar campos no permitidos.
            if ($value === null || is_scalar($value)) {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}
