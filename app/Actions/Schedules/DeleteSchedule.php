<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;

class DeleteSchedule
{
    public function handle(Schedule $schedule): void
    {
        $schedule->delete();
    }
}
