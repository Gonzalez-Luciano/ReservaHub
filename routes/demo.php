<?php

use App\Http\Controllers\Demo\SimulatedCheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('signed')->prefix('demo/pagos')->name('demo.payments.')->group(function () {
    Route::get('{externalId}/checkout', [SimulatedCheckoutController::class, 'show'])->name('checkout');
    Route::post('{externalId}/resultado', [SimulatedCheckoutController::class, 'outcome'])->name('outcome');
});
