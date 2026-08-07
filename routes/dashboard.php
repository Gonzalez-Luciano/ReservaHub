<?php

use App\Http\Controllers\Dashboard\EmployeeController;
use App\Http\Controllers\Dashboard\EmployeeInvitationController;
use App\Http\Controllers\Dashboard\ServiceController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::resource('services', ServiceController::class)->except(['show']);

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('employees/invitations', [EmployeeInvitationController::class, 'store'])->name('employees.invitations.store');
        Route::post('employees/invitations/{invitation}/resend', [EmployeeInvitationController::class, 'resend'])->name('employees.invitations.resend');
        Route::delete('employees/invitations/{invitation}', [EmployeeInvitationController::class, 'destroy'])->name('employees.invitations.destroy');
    });
});
