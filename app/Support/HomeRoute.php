<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Destino de un usuario recién autenticado: el lugar donde efectivamente
 * trabaja, no la landing pública.
 *
 * Es fuente única de verdad a propósito. El login, el registro y cualquier
 * otro punto que deje al usuario autenticado tienen que coincidir en la
 * respuesta, o el mismo usuario aterriza en pantallas distintas según por
 * dónde entró.
 */
class HomeRoute
{
    /**
     * La condición de /dashboard es exactamente la que exige
     * App\Http\Middleware\EnsureBusinessContext. Si se relajara acá, el
     * redirect llevaría a un 403: una pared en vez de una pantalla.
     */
    public static function for(?User $user): string
    {
        if ($user === null) {
            return '/';
        }

        if (in_array($user->role, Role::businessStaff(), true)
            && $user->hasBusiness()
            && $user->is_active
            && $user->business->is_active
        ) {
            return '/dashboard';
        }

        if ($user->role === Role::Customer) {
            return '/mis-reservas';
        }

        return '/';
    }
}
