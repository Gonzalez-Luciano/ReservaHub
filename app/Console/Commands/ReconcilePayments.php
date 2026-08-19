<?php

namespace App\Console\Commands;

use App\Actions\Payments\ApplyPaymentResult;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Scopes\BusinessScope;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentResult;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\UnknownProviderPaymentException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repara la divergencia entre la verdad local y la del proveedor cuando la
 * entrega del evento se perdió o falló de forma permanente. Aplica el resultado
 * por el MISMO camino que el webhook (ApplyPaymentResult) y nunca toca
 * `webhook_events`: esto no es una entrega de evento.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Consulta al proveedor los pagos locales no terminales y aplica su estado.';

    public function handle(PaymentGateway $gateway, ApplyPaymentResult $applyPaymentResult): int
    {
        $cadenceMinutes = (int) config('payments.reconcile.cadence_minutes');
        $batch = (int) config('payments.reconcile.batch');

        // Trabajo acotado por estado + cadencia + lote, nunca por antigüedad: un
        // pago viejo todavía pendiente es justo la divergencia a reparar.
        $payments = Payment::withoutGlobalScope(BusinessScope::class)
            ->where('status', PaymentStatus::Pending)
            ->where('provider', $gateway->name())
            ->where(function ($query) use ($cadenceMinutes) {
                $query->whereNull('last_reconcile_attempt_at')
                    ->orWhere('last_reconcile_attempt_at', '<=', now()->subMinutes($cadenceMinutes));
            })
            ->orderByRaw('last_reconcile_attempt_at asc nulls first')
            ->orderBy('created_at')
            ->limit($batch)
            ->get();

        $applied = 0;

        foreach ($payments as $payment) {
            // El INTENTO se estampa siempre, incluso si el proveedor falla: si
            // solo se estampara el éxito, un subconjunto que falla siempre
            // monopolizaría todas las corridas y mataría de hambre al resto.
            $payment->forceFill(['last_reconcile_attempt_at' => now()])->save();

            try {
                $snapshot = $gateway->fetchPayment($payment->external_id);
            } catch (GatewayUnavailableException $e) {
                Log::warning('payments.reconcile_gateway_unavailable', [
                    'payment_id' => $payment->id,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            } catch (UnknownProviderPaymentException $e) {
                Log::warning('payments.reconcile_unknown_payment', [
                    'payment_id' => $payment->id,
                    'external_id' => $payment->external_id,
                ]);

                continue;
            }

            $result = $applyPaymentResult->handle($payment, PaymentResult::fromSnapshot($snapshot));

            // `$payment` puede estar obsoleto frente a lo que ApplyPaymentResult
            // escribió en su propia instancia relockeada internamente; es
            // inofensivo porque `save()` solo persiste atributos sucios y aquí
            // el único atributo tocado es `last_reconciled_at`.
            $payment->forceFill(['last_reconciled_at' => now()])->save();

            if ($result->accepted && $result->reasonCode !== 'provider_still_pending') {
                $applied++;
            }
        }

        $this->info("Pagos inspeccionados: {$payments->count()}. Resultados aplicados: {$applied}.");

        return self::SUCCESS;
    }
}
