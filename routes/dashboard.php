<?php

use App\Http\Controllers\Dashboard\EmployeeController;
use App\Http\Controllers\Dashboard\EmployeeInvitationController;
use App\Http\Controllers\Dashboard\EmployeeServiceController;
use App\Http\Controllers\Dashboard\ScheduleController;
use App\Http\Controllers\Dashboard\ServiceController;
use App\Http\Controllers\Dashboard\TimeOffController;
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
        Route::put('employees/{employee}/services', [EmployeeServiceController::class, 'update'])->name('employees.services.update');
        Route::get('employees/{employee}/schedule', [ScheduleController::class, 'index'])->name('employees.schedule.index');
        Route::post('employees/{employee}/schedule', [ScheduleController::class, 'store'])->name('employees.schedule.store');
        Route::put('schedules/{schedule}', [ScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');
        Route::post('schedules/{schedule}/breaks', [ScheduleController::class, 'storeBreak'])->name('schedules.breaks.store');
        Route::delete('schedule-breaks/{scheduleBreak}', [ScheduleController::class, 'destroyBreak'])->name('schedule-breaks.destroy');
        Route::post('employees/{employee}/time-offs', [TimeOffController::class, 'store'])->name('employees.time-offs.store');
        Route::delete('time-offs/{timeOff}', [TimeOffController::class, 'destroy'])->name('time-offs.destroy');
    });
});
