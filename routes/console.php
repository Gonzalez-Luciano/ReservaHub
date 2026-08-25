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

// Contrato de la demo: el dataset funcional dura una semana, pero las
// credenciales publicadas se restauran todos los días. La limpieza diaria de
// Mailpit es otro servicio y la ejecuta operaciones, no este scheduler.
Schedule::command('demo:restore-access')
    ->dailyAt('00:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping(10);
