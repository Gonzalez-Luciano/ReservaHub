<?php

namespace App\Http\Controllers\Demo;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverSimulatedProviderWebhook;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Scopes\BusinessScope;
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

        [$payment, $booking] = $this->localPaymentAndBookingFor($externalId, $gateway);

        return Inertia::render('Demo/Checkout', [
            'payment' => [
                'external_id' => $snapshot->externalId,
                'status' => $snapshot->status->value,
                'amount' => $snapshot->amount,
                'currency' => $snapshot->currency,
            ],
            // La firma del GET no autoriza el POST: la URL de resultado se emite
            // recién acá, ya validado el acceso a esta página. Su vencimiento
            // nunca excede lo que le queda a la ventana de pago de la reserva
            // (mismo clamp que CreateBooking aplica a `payment_expires_at`
            // frente al inicio del turno): un checkout mostrado tarde no puede
            // emitir un resultado que sobreviva a la ventana ya vencida.
            'outcome_url' => URL::temporarySignedRoute(
                'demo.payments.outcome',
                $this->clampedExpiry($booking),
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

        [$payment] = $this->localPaymentAndBookingFor($externalId, $gateway);

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

            // El pago local es la autoridad: si ya dejó de estar `pending`
            // (aprobado/rechazado por una entrega anterior, o el sweeper de
            // expiración ya actuó), no hay nada que aplicar. Mismo no-op que
            // el caso "el proveedor ya no acepta esto" — no muta el proveedor
            // ni encola una entrega para un pago que la aplicación ya cerró.
            if ($payment->status !== PaymentStatus::Pending) {
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

    /**
     * El pago local se busca explícitamente por (provider, external_id), con
     * el scope de negocio levantado: esta ruta llega firmada, sin
     * autenticación ni contexto de negocio. Sin un `Payment` local no hay
     * reserva ni ventana que verificar, así que es el mismo 404 que un
     * `external_id` desconocido para el proveedor.
     *
     * @return array{0: Payment, 1: Booking}
     */
    private function localPaymentAndBookingFor(string $externalId, PaymentGateway $gateway): array
    {
        $payment = Payment::withoutGlobalScope(BusinessScope::class)
            ->where('provider', $gateway->name())
            ->where('external_id', $externalId)
            ->first();

        if ($payment === null) {
            abort(404);
        }

        $booking = Booking::withoutGlobalScope(BusinessScope::class)->find($payment->booking_id);

        if ($booking === null) {
            abort(404);
        }

        return [$payment, $booking];
    }

    private function clampedExpiry(Booking $booking): CarbonImmutable
    {
        $windowEnd = CarbonImmutable::now()->addMinutes((int) config('payments.window_minutes'));
        $deadline = $booking->payment_expires_at?->toImmutable();

        return $deadline !== null && $deadline->lessThan($windowEnd) ? $deadline : $windowEnd;
    }
}
