<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;

class UpdateSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Schedule $schedule, array $data): Schedule
    {
        $schedule->update($data);

        return $schedule;
    }
}
