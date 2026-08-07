<?php

use App\Http\Controllers\Auth\InvitationAcceptController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('invitations/{token}/accept', [InvitationAcceptController::class, 'show'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('invitations.accept');

    Route::post('invitations/{token}/accept', [InvitationAcceptController::class, 'store'])
        ->middleware('throttle:6,1');
});
