<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Middleware\BindPublicBusiness;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api-login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });

    Route::middleware(['auth:sanctum', 'business'])->group(function () {
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    });

    Route::middleware(['auth:sanctum', BindPublicBusiness::class])
        ->prefix('businesses/{business:slug}')
        ->name('public.')
        ->group(function () {
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        });
});
