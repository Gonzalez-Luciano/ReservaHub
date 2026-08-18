<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Exceptions\UnknownProviderPaymentException;
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
        ]);
    }
}
