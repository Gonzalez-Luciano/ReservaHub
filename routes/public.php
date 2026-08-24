<?php

use App\Http\Controllers\ComoFuncionaController;
use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\BookingPaymentController;
use App\Http\Controllers\Public\BusinessController;
use App\Http\Controllers\Public\MyBookingsController;
use App\Http\Middleware\BindPublicBusiness;
use Illuminate\Support\Facades\Route;

// Fuera del grupo con prefijo de slug: ese lleva BindPublicBusiness, que exige un slug.
Route::get('negocios', [BusinessController::class, 'index'])->name('public.business.index');

// Guía permanente de la demo compartida — no depende del proveedor de pagos,
// por eso vive acá y no en routes/demo.php.
Route::get('como-funciona', ComoFuncionaController::class)->name('public.guide');

Route::prefix('negocios/{business:slug}')->middleware(BindPublicBusiness::class)->name('public.business.')->group(function () {
    Route::get('/', [BusinessController::class, 'show'])->name('show');
    Route::get('/reservar', [BookingController::class, 'create'])->middleware('auth')->name('booking.create');
    Route::post('/reservar', [BookingController::class, 'store'])->middleware('auth')->name('booking.store');
});

Route::middleware('auth')->prefix('mis-reservas')->name('public.bookings.mine.')->group(function () {
    Route::get('/', [MyBookingsController::class, 'index'])->name('index');
    Route::post('/{booking}/cancel', [MyBookingsController::class, 'cancel'])->name('cancel')->whereNumber('booking');
    Route::put('/{booking}/reschedule', [MyBookingsController::class, 'reschedule'])->name('reschedule')->whereNumber('booking');
    Route::get('/{booking}/reschedule-slots', [MyBookingsController::class, 'rescheduleSlots'])->name('reschedule-slots')->whereNumber('booking');
    Route::post('/{booking}/pagos', [BookingPaymentController::class, 'store'])->name('payments.store')->whereNumber('booking');
});
