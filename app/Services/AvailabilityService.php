<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\TimeOff;
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

        $freeIntervals = [[$windowStart, $windowEnd]];

        foreach ($schedule->breaks as $break) {
            $freeIntervals = $this->subtractInterval(
                $freeIntervals,
                $localDate->setTimeFromTimeString($break->start_time),
                $localDate->setTimeFromTimeString($break->end_time),
            );
        }

        $timeOffs = TimeOff::query()
            ->where('employee_id', $employee->id)
            ->where('starts_at', '<', $windowEnd->utc())
            ->where('ends_at', '>', $windowStart->utc())
            ->get();

        foreach ($timeOffs as $timeOff) {
            $freeIntervals = $this->subtractInterval(
                $freeIntervals,
                $timeOff->starts_at->toImmutable()->setTimezone($timezone),
                $timeOff->ends_at->toImmutable()->setTimezone($timezone),
            );
        }

        $candidates = $this->generateCandidates($freeIntervals, $service->duration_minutes);

        $busySpans = Booking::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::Completed])
            ->where('starts_at', '<', $windowEnd->utc())
            ->where('starts_at', '>', $windowStart->utc()->subDay())
            ->with('service')
            ->get()
            ->map(fn (Booking $booking) => [
                $booking->starts_at->toImmutable()->setTimezone($timezone),
                $booking->ends_at->toImmutable()->setTimezone($timezone)->addMinutes($booking->service->buffer_minutes),
            ])
            ->all();

        $slots = [];
        foreach ($candidates as $start) {
            $end = $start->addMinutes($service->duration_minutes);
            $occupiedEnd = $end->addMinutes($service->buffer_minutes);

            if ($this->overlapsAny($start, $occupiedEnd, $busySpans)) {
                continue;
            }

            $slots[] = ['starts_at' => $start, 'ends_at' => $end];
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

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $spans
     */
    private function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $spans): bool
    {
        foreach ($spans as [$spanStart, $spanEnd]) {
            if ($start->lt($spanEnd) && $spanStart->lt($end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function subtractInterval(array $intervals, CarbonImmutable $blockStart, CarbonImmutable $blockEnd): array
    {
        $result = [];

        foreach ($intervals as [$start, $end]) {
            if ($blockEnd->lte($start) || $blockStart->gte($end)) {
                $result[] = [$start, $end];

                continue;
            }

            if ($blockStart->gt($start)) {
                $result[] = [$start, $blockStart];
            }

            if ($blockEnd->lt($end)) {
                $result[] = [$blockEnd, $end];
            }
        }

        return $result;
    }
}
