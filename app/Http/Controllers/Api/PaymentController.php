<?php

namespace App\Http\Controllers\Api;

use App\Actions\Payments\InitiatePayment;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\Concerns\ResolvesBookingScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ResolvesBookingScope;

    public function index(Request $request, int $booking): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('viewAny', [Payment::class, $model]);

        $payments = $model->payments()->orderByDesc('id')->get()
            ->each(fn (Payment $payment) => $payment->setRelation('booking', $model));

        return ApiResponse::success(PaymentResource::collection($payments));
    }

    public function store(Request $request, int $booking, InitiatePayment $action): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('create', [Payment::class, $model]);

        $alreadyLive = $model->payments()->where('status', PaymentStatus::Pending)->exists();

        $payment = $action->handle($model, $request->user());
        $payment->setRelation('booking', $model->refresh());

        return ApiResponse::success(
            PaymentResource::make($payment),
            $alreadyLive ? 'Ya había un pago en curso para esta reserva.' : 'Pago iniciado.',
            $alreadyLive ? 200 : 201,
        );
    }

    public function show(Request $request, int $booking, int $payment): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        // El pago se busca DENTRO de la reserva: nunca por binding implícito,
        // porque `Payment` lleva scope de negocio y estas rutas no tienen
        // contexto de negocio cuando el actor es un cliente.
        $paymentModel = $model->payments()->findOrFail($payment);
        $paymentModel->setRelation('booking', $model);

        $this->authorize('view', $paymentModel);

        return ApiResponse::success(PaymentResource::make($paymentModel));
    }
}
