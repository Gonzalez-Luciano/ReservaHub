<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserStatusController;
use App\Http\Middleware\BindPublicBusiness;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api-login')
        ->name('auth.login');

    Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
        ->middleware('throttle:payment-webhooks')
        ->name('webhooks.payments');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('account', [AccountController::class, 'show'])->name('account.show');
        Route::patch('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
        Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');

        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show')->whereNumber('booking');
        Route::patch('bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update')->whereNumber('booking');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel')->whereNumber('booking');

        Route::get('bookings/{booking}/payments', [PaymentController::class, 'index'])
            ->name('bookings.payments.index')->whereNumber('booking');
        Route::post('bookings/{booking}/payments', [PaymentController::class, 'store'])
            ->name('bookings.payments.store')->whereNumber('booking');
        Route::get('bookings/{booking}/payments/{payment}', [PaymentController::class, 'show'])
            ->name('bookings.payments.show')->whereNumber('booking')->whereNumber('payment');

        // Fuera del prefijo businesses/{business:slug}: ese grupo exige BindPublicBusiness,
        // que requiere un slug. Sin EnsureBusinessContext: no consulta modelos con BusinessScope.
        Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
    });

    Route::middleware(['auth:sanctum', 'business'])->group(function () {
        Route::get('business', [BusinessController::class, 'show'])->name('business.show');
        Route::put('business', [BusinessController::class, 'update'])->name('business.update');
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('availability', [AvailabilityController::class, 'index'])->name('availability.index');
        Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm')->whereNumber('booking');
        Route::put('users/{user}/status', [UserStatusController::class, 'update'])
            ->name('users.status.update')
            ->whereNumber('user');
        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])
            ->name('holidays.destroy')
            ->whereNumber('holiday');
    });

    Route::middleware(['auth:sanctum', BindPublicBusiness::class])
        ->prefix('businesses/{business:slug}')
        ->name('public.')
        ->group(function () {
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
            Route::get('availability', [AvailabilityController::class, 'index'])->name('availability.index');
            Route::post('bookings', [BookingController::class, 'storeForCustomer'])->name('bookings.store');
        });
});
