<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Support\UserAccessRevoker;
use Illuminate\Support\Facades\DB;

class ChangePassword
{
    public function __construct(private readonly UserAccessRevoker $revoker) {}

    /**
     * @param  string|null  $keepSessionId  Sesión web a preservar. `null` desde
     *                                      la API: ahí cae todo, incluido el
     *                                      token que hizo la llamada.
     */
    public function handle(User $user, string $password, ?string $keepSessionId = null): void
    {
        DB::transaction(function () use ($user, $password, $keepSessionId): void {
            // El cast `hashed` del modelo hashea al asignar.
            $user->forceFill(['password' => $password])->save();

            $this->revoker->revoke($user, $keepSessionId);
        });
    }
}
