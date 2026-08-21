<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Único canal de la aplicación. El predicado es la unión exacta de lo que HTTP
 * ya exige: EnsureBusinessContext (rol de staff, usuario activo, negocio
 * activo) más la comprobación de negocio de BookingPolicy::viewAny.
 *
 * El parámetro se tipa string y se compara como string a propósito: con int,
 * PHP coaccionaría '05' y '5abc' a 5 y un identificador forjado entraría a un
 * canal ajeno.
 */
Broadcast::channel('business.{businessId}', function (User $user, string $businessId): bool {
    return in_array($user->role, Role::businessStaff(), true)
        && $user->is_active
        && (string) $user->business_id === $businessId
        && (bool) $user->business?->is_active;
});
