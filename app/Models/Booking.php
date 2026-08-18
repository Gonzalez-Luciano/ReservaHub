<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'employee_id', 'service_id', 'starts_at', 'ends_at', 'status', 'price', 'deposit_amount', 'payment_expires_at', 'notes', 'source', 'cancelled_at'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => BookingStatus::class,
            'price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'payment_expires_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
