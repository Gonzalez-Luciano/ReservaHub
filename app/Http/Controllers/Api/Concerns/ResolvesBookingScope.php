<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ResolvesBookingScope
{
    /**
     * Bookings visible to the acting user.
     *
     * Staff read their own business (the global BusinessScope filters once the
     * business is bound); a customer has no business of their own and reads
     * their bookings across every business, so the scope must be lifted.
     *
     * @return Builder<Booking>
     */
    protected function bookingQueryFor(User $user): Builder
    {
        abort_unless($user->is_active, 403);

        if (in_array($user->role, Role::businessStaff(), true)) {
            abort_unless($user->hasBusiness(), 403);
            abort_unless($user->business->is_active, 403);

            app()->instance(Business::class, $user->business);

            return Booking::query();
        }

        return Booking::withoutGlobalScope(BusinessScope::class)->where('customer_id', $user->id);
    }

    protected function findBookingFor(User $user, int $bookingId): Booking
    {
        return $this->bookingQueryFor($user)->findOrFail($bookingId);
    }

    /**
     * Relations every booking payload needs. `service` lifts the global scope
     * because a customer request has no business bound.
     *
     * @return array<int|string, mixed>
     */
    protected function bookingRelations(): array
    {
        return [
            'business:id,name,slug,timezone',
            'employee:id,name,email,is_active',
            'customer:id,name,email,role,business_id',
            'service' => fn ($query) => $query->withoutGlobalScope(BusinessScope::class),
        ];
    }
}
