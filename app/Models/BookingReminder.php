<?php

namespace App\Models;

use App\Enums\ReminderType;
use Database\Factories\BookingReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'type', 'sent_at'])]
class BookingReminder extends Model
{
    /** @use HasFactory<BookingReminderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'sent_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
