<?php

use App\Http\Controllers\Dashboard\BookingController;
use App\Http\Controllers\Dashboard\BookingPaymentController;
use App\Http\Controllers\Dashboard\BusinessSettingsController;
use App\Http\Controllers\Dashboard\EmployeeController;
use App\Http\Controllers\Dashboard\EmployeeInvitationController;
use App\Http\Controllers\Dashboard\EmployeeServiceController;
use App\Http\Controllers\Dashboard\HolidayController;
use App\Http\Controllers\Dashboard\ScheduleController;
use App\Http\Controllers\Dashboard\ServiceController;
use App\Http\Controllers\Dashboard\TimeOffController;
use App\Http\Controllers\Dashboard\UserStatusController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('settings', [BusinessSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [BusinessSettingsController::class, 'update'])->name('settings.update');

        Route::resource('services', ServiceController::class)->except(['show']);

        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
        Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('bookings/{booking}/complete', [BookingController::class, 'complete'])->name('bookings.complete');
        Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.noShow');
        Route::put('bookings/{booking}/reschedule', [BookingController::class, 'reschedule'])->name('bookings.reschedule');
        Route::get('bookings/{booking}/reschedule-slots', [BookingController::class, 'rescheduleSlots'])->name('bookings.reschedule-slots');
        Route::post('bookings/{booking}/pagos', [BookingPaymentController::class, 'store'])->name('bookings.payments.store');

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

        Route::put('users/{user}/status', [UserStatusController::class, 'update'])->name('users.status.update');

        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
    });
});
