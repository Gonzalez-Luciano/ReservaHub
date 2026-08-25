<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\HomeRoute;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect($this->destination($request));
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect($this->destination($request));
    }

    /**
     * `?verified=1` viene del scaffold y hoy no lo lee nadie: ninguna pantalla
     * muestra un aviso a partir de esa marca. Se conserva porque cambiar el
     * acuse de recibo de la verificación es otra decisión que la de a dónde
     * mandar al usuario.
     */
    private function destination(EmailVerificationRequest $request): string
    {
        return HomeRoute::for($request->user()).'?verified=1';
    }
}
