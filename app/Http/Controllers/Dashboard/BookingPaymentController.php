<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Payments\InitiatePayment;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookingPaymentController extends Controller
{
    public function store(Request $request, Booking $booking, InitiatePayment $action, PaymentGateway $gateway): RedirectResponse
    {
        $this->authorize('create', [Payment::class, $booking]);

        $payment = $action->handle($booking, $request->user());

        return redirect()->away($gateway->checkoutUrl($payment->external_id, $payment->expires_at->toImmutable()));
    }
}
