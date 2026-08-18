<?php

namespace App\Http\Controllers\Demo;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverSimulatedProviderWebhook;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Exceptions\UnknownProviderPaymentException;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SimulatedCheckoutController extends Controller
{
    public function show(string $externalId, PaymentGateway $gateway): Response
    {
        try {
            $snapshot = $gateway->fetchPayment($externalId);
        } catch (UnknownProviderPaymentException) {
            abort(404);
        }

        return Inertia::render('Demo/Checkout', [
            'payment' => [
                'external_id' => $snapshot->externalId,
                'status' => $snapshot->status->value,
                'amount' => $snapshot->amount,
                'currency' => $snapshot->currency,
            ],
            // La firma del GET no autoriza el POST: la URL de resultado se emite
            // recién acá, ya validado el acceso a esta página.
            'outcome_url' => URL::temporarySignedRoute(
                'demo.payments.outcome',
                CarbonImmutable::now()->addMinutes((int) config('payments.window_minutes')),
                ['externalId' => $snapshot->externalId],
            ),
            'return_url' => route('public.bookings.mine.index'),
        ]);
    }

    public function outcome(Request $request, string $externalId, PaymentGateway $gateway): RedirectResponse
    {
        /** @var SimulatedPaymentGateway $gateway */
        $outcome = (string) $request->input('outcome');

        if (! in_array($outcome, ['approved', 'rejected', 'abandoned'], true)) {
            // No usamos ValidationException: para una petición que no pide JSON
            // (esta no lleva Accept: application/json) su comportamiento por
            // defecto es un redirect 302 con errores en sesión, no un 422.
            abort(422, 'Resultado de pago simulado no soportado.');
        }

        try {
            // El proveedor expira por su cuenta al ser consultado: se lo
            // consulta primero para que un checkout ya vencido se refleje
            // como tal antes de decidir qué hacer con el resultado, sin que
            // ninguna acción de "outcome" fuerce esa expiración.
            $gateway->fetchPayment($externalId);

            // Abandonar abandona de verdad: no muta al proveedor y no emite
            // evento. El proveedor expira solo y el resto del flujo (reconcile
            // + expire-unpaid) demuestra el caso completo.
            if ($outcome === 'abandoned') {
                return redirect()->route('public.bookings.mine.index');
            }

            $status = $outcome === 'approved' ? PaymentStatus::Approved : PaymentStatus::Rejected;

            if ($gateway->applyOutcome($externalId, $status)) {
                DeliverSimulatedProviderWebhook::dispatch($externalId, $status, 'evt_'.Str::ulid());
            }
        } catch (UnknownProviderPaymentException) {
            abort(404);
        }

        return redirect()->route('public.bookings.mine.index');
    }
}
