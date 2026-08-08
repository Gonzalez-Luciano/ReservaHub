<?php

namespace App\Services;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

class AvailabilityService
{
    /**
     * @return array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function getAvailableSlots(Business $business, Service $service, User $employee, CarbonImmutable $date): array
    {
        app()->instance(Business::class, $business);

        $timezone = $business->timezone;
        $localDate = $date->setTimezone($timezone)->startOfDay();
        $dayOfWeek = DayOfWeek::from($localDate->dayOfWeek);

        $schedule = Schedule::query()
            ->where('employee_id', $employee->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->first();

        if (! $schedule) {
            return [];
        }

        $windowStart = $localDate->setTimeFromTimeString($schedule->start_time);
        $windowEnd = $localDate->setTimeFromTimeString($schedule->end_time);

        $candidates = $this->generateCandidates([[$windowStart, $windowEnd]], $service->duration_minutes);

        $slots = [];
        foreach ($candidates as $start) {
            $slots[] = [
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($service->duration_minutes),
            ];
        }

        return $slots;
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return array<int, CarbonImmutable>
     */
    private function generateCandidates(array $intervals, int $durationMinutes): array
    {
        $candidates = [];

        foreach ($intervals as [$start, $end]) {
            $cursor = $start;
            while ($cursor->addMinutes($durationMinutes)->lte($end)) {
                $candidates[] = $cursor;
                $cursor = $cursor->addMinutes($durationMinutes);
            }
        }

        return $candidates;
    }
}
