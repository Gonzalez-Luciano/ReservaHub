<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\EmployeeInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'email', 'name', 'token', 'invited_by_id', 'expires_at', 'accepted_at'])]
class EmployeeInvitation extends Model
{
    /** @use HasFactory<EmployeeInvitationFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }
}
