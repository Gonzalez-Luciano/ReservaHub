<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});
