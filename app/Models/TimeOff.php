<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\TimeOffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'starts_at', 'ends_at', 'reason'])]
class TimeOff extends Model
{
    /** @use HasFactory<TimeOffFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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
}
