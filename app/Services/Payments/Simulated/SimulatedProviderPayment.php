<?php

namespace App\Services\Payments\Simulated;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Estado del proveedor simulado. Vive junto al adapter, no en app/Models,
 * porque no es dato de dominio: solo el adapter puede leerlo o escribirlo.
 */
#[Fillable(['external_id', 'status', 'amount', 'currency', 'approved_at', 'expires_at', 'payload'])]
class SimulatedProviderPayment extends Model
{
    protected $table = 'simulated_provider_payments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'payload' => 'array',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
