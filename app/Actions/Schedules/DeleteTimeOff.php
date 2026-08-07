<?php

namespace App\Actions\Schedules;

use App\Models\TimeOff;

class DeleteTimeOff
{
    public function handle(TimeOff $timeOff): void
    {
        $timeOff->delete();
    }
}
