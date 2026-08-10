<?php

use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\BusinessController;
use App\Http\Middleware\BindPublicBusiness;
use Illuminate\Support\Facades\Route;

Route::prefix('negocios/{business:slug}')->middleware(BindPublicBusiness::class)->name('public.business.')->group(function () {
    Route::get('/', [BusinessController::class, 'show'])->name('show');
    Route::get('/reservar', [BookingController::class, 'create'])->middleware('auth')->name('booking.create');
    Route::post('/reservar', [BookingController::class, 'store'])->middleware('auth')->name('booking.store');
});
