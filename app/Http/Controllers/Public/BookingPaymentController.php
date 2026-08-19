<?php

namespace App\Http\Controllers\Public;

use App\Actions\Payments\InitiatePayment;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Scopes\BusinessScope;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookingPaymentController extends Controller
{
    public function store(Request $request, int $booking, InitiatePayment $action, PaymentGateway $gateway): RedirectResponse
    {
        $model = Booking::withoutGlobalScope(BusinessScope::class)
            ->where('customer_id', $request->user()->id)
            ->findOrFail($booking);

        $this->authorize('create', [Payment::class, $model]);

        $payment = $action->handle($model, $request->user());

        return redirect()->away($gateway->checkoutUrl($payment->external_id, $payment->expires_at->toImmutable()));
    }
}
