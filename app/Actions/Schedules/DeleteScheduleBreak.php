<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleBreak;

class DeleteScheduleBreak
{
    public function handle(ScheduleBreak $scheduleBreak): void
    {
        $scheduleBreak->delete();
    }
}
