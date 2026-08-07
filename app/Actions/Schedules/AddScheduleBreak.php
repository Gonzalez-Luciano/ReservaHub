<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;
use App\Models\ScheduleBreak;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AddScheduleBreak
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Schedule $schedule, array $data): ScheduleBreak
    {
        // Eloquent returns `time` columns as plain strings — compare via Carbon, not `<`/`>`.
        $breakStart = Carbon::createFromFormat('H:i', $data['start_time']);
        $breakEnd = Carbon::createFromFormat('H:i', $data['end_time']);
        $scheduleStart = Carbon::parse($schedule->start_time);
        $scheduleEnd = Carbon::parse($schedule->end_time);

        if ($breakStart->lt($scheduleStart) || $breakEnd->gt($scheduleEnd)) {
            throw ValidationException::withMessages([
                'start_time' => 'La pausa debe estar dentro del horario del turno.',
            ]);
        }

        return $schedule->breaks()->create($data);
    }
}
