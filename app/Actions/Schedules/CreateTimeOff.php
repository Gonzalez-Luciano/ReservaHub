<?php

namespace App\Actions\Schedules;

use App\Models\TimeOff;

class CreateTimeOff
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): TimeOff
    {
        return TimeOff::create($data);
    }
}
