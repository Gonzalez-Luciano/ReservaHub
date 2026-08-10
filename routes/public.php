<?php

use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\BusinessController;
use App\Http\Controllers\Public\MyBookingsController;
use App\Http\Middleware\BindPublicBusiness;
use Illuminate\Support\Facades\Route;

Route::prefix('negocios/{business:slug}')->middleware(BindPublicBusiness::class)->name('public.business.')->group(function () {
    Route::get('/', [BusinessController::class, 'show'])->name('show');
    Route::get('/reservar', [BookingController::class, 'create'])->middleware('auth')->name('booking.create');
    Route::post('/reservar', [BookingController::class, 'store'])->middleware('auth')->name('booking.store');
});

Route::middleware('auth')->prefix('mis-reservas')->name('public.bookings.mine.')->group(function () {
    Route::get('/', [MyBookingsController::class, 'index'])->name('index');
    Route::post('/{booking}/cancel', [MyBookingsController::class, 'cancel'])->name('cancel');
    Route::put('/{booking}/reschedule', [MyBookingsController::class, 'reschedule'])->name('reschedule');
    Route::get('/{booking}/reschedule-slots', [MyBookingsController::class, 'rescheduleSlots'])->name('reschedule-slots');
});
