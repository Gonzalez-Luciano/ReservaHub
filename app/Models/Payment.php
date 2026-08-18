<?php

namespace App\Models;

use App\Enums\PaymentApplicationOutcome;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id', 'booking_id', 'provider', 'external_id', 'status', 'amount', 'currency',
    'expires_at', 'paid_at', 'applied_at', 'application_outcome', 'failure_reason',
    'last_snapshot', 'last_reconcile_attempt_at', 'last_reconciled_at',
])]
class Payment extends Model
{
    use BelongsToBusiness;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'application_outcome' => PaymentApplicationOutcome::class,
            'amount' => 'decimal:2',
            'last_snapshot' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'applied_at' => 'datetime',
            'last_reconcile_attempt_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
