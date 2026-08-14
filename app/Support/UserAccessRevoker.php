<?php

namespace App\Support;

use App\Exceptions\UnsupportedSessionDriverException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único mecanismo de revocación de acceso de la aplicación.
 *
 * Corta los tres vectores de re-autenticación: la sesión de base de datos,
 * la cookie remember-me y los tokens de Sanctum. `AuthenticateSession` no
 * está en el grupo `web`, así que `Auth::logoutOtherDevices()` no invalidaría
 * nada acá y no se usa.
 */
class UserAccessRevoker
{
    /**
     * @param  string|null  $keepSessionId  Sesión a preservar (la del propio
     *                                      request). `null` revoca todas.
     *
     * @throws UnsupportedSessionDriverException cuando el driver de sesión no
     *                                           es `database` y, por lo tanto, las sesiones ajenas no se pueden
     *                                           invalidar. Falla cerrado a propósito: no hay revocación parcial
     *                                           silenciosa.
     */
    public function revoke(User $user, ?string $keepSessionId = null): void
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            throw UnsupportedSessionDriverException::for($driver);
        }

        $user->setRememberToken(Str::random(60));
        $user->save();

        $user->tokens()->delete();

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
