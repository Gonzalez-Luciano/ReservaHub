<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;
use App\Models\ScheduleBreak;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class UpdateSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Schedule $schedule, array $data): Schedule
    {
        if (isset($data['start_time'], $data['end_time'])) {
            $newStart = Carbon::createFromFormat('H:i', $data['start_time']);
            $newEnd = Carbon::createFromFormat('H:i', $data['end_time']);

            $outOfRange = $schedule->breaks()->get()->contains(function (ScheduleBreak $break) use ($newStart, $newEnd) {
                return Carbon::parse($break->start_time)->lt($newStart) || Carbon::parse($break->end_time)->gt($newEnd);
            });

            if ($outOfRange) {
                throw ValidationException::withMessages([
                    'start_time' => 'El nuevo horario deja pausas existentes fuera de rango. Eliminá o ajustá esas pausas primero.',
                ]);
            }
        }

        $schedule->update($data);

        return $schedule;
    }
}
