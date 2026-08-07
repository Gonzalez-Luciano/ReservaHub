<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;

class CreateSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Schedule
    {
        return Schedule::create($data);
    }
}
