<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ComoFuncionaController extends Controller
{
    /**
     * Guía pública y permanente de la demo compartida. `mailUrl` refleja
     * `config('app.demo_mail_url')` (espejo de VITE_DEMO_MAIL_URL): sin
     * definir, el CTA del buzón no tiene nada que renderizar.
     */
    public function __invoke(): Response
    {
        return Inertia::render('ComoFunciona', [
            'mailUrl' => config('app.demo_mail_url'),
        ]);
    }
}
