<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
});

require __DIR__.'/auth.php';
require __DIR__.'/dashboard.php';
require __DIR__.'/invitations.php';
require __DIR__.'/public.php';
