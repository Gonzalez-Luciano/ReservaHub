<?php

use App\Http\Controllers\HomeController;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

require __DIR__.'/account.php';
require __DIR__.'/auth.php';
require __DIR__.'/dashboard.php';
require __DIR__.'/invitations.php';
require __DIR__.'/public.php';

// El checkout simulado existe solo mientras el proveedor ligado sea el simulado.
if (app(PaymentGateway::class)->name() === 'simulated') {
    require __DIR__.'/demo.php';
}
