<?php

namespace App\Http\Controllers\Account;

use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
            ],
            // Sigue el patrón ya usado por PasswordResetLinkController y
            // EmailVerificationPromptController: el flash `status` se pasa
            // explícito como prop de la página, no vía share() global.
            'status' => session('status'),
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $action): RedirectResponse
    {
        $action->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('email'),
        );

        return redirect()->route('account.edit')->with('status', 'Perfil actualizado.');
    }
}
