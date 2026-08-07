<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'day_of_week', 'start_time', 'end_time', 'is_active'])]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(ScheduleBreak::class);
    }
}
