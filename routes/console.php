<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('bookings:expire-unpaid')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('payments:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
