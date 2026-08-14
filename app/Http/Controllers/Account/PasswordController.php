<?php

namespace App\Http\Controllers\Account;

use App\Actions\Account\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request, ChangePassword $action): RedirectResponse
    {
        $action->handle(
            $request->user(),
            $request->validated('password'),
            $request->session()->getId(),
        );

        // Rota también el ID de la sesión actual (anti-fijación). El revoker ya
        // borró las demás.
        $request->session()->regenerate();

        return redirect()->route('account.edit')->with('status', 'Contraseña actualizada.');
    }
}
