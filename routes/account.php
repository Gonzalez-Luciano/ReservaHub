<?php

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('account', [ProfileController::class, 'edit'])->name('account.edit');
    Route::patch('account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::put('account/password', [PasswordController::class, 'update'])->name('account.password.update');
});
