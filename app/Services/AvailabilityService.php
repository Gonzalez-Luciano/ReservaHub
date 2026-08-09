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
     * Free slots for an employee on a given calendar day.
     *
     * `$date`'s year/month/day — read from whatever timezone `$date` itself
     * carries — is the calendar date being queried **in the business's own
     * timezone**. `$date` is never converted as an instant, so a caller may pass
     * e.g. a UTC-midnight date for a business in any timezone and still get that
     * same calendar day back. Time-of-day on `$date` is ignored.
     *
     * @return array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     *
     * @throws \InvalidArgumentException when `$service` or `$employee` belongs to another business.
     */
    public function getAvailableSlots(Business $business, Service $service, User $employee, CarbonImmutable $date, ?int $excludeBookingId = null): array
    {
        if ($service->business_id !== $business->id || $employee->business_id !== $business->id) {
            throw new \InvalidArgumentException('Service and employee must belong to the given business.');
        }

        $timezone = $business->timezone;
        $localDate = CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, $timezone);
        $dayOfWeek = DayOfWeek::from($localDate->dayOfWeek);

        $schedule = Schedule::query()
            ->where('business_id', $business->id)
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
            ->where('business_id', $business->id)
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
            ->where('business_id', $business->id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::Completed])
            // A candidate's occupied span reaches to `start + duration + buffer`,
            // so a booking may start up to the requested service's buffer past the
            // window end and still overlap the last candidate. A booking starting
            // at or after that bound provably cannot overlap any candidate.
            ->where('starts_at', '<', $windowEnd->utc()->addMinutes($service->buffer_minutes))
            ->where('starts_at', '>', $windowStart->utc()->subDay())
            ->when($excludeBookingId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->with('service')
            ->get()
            ->map(fn (Booking $booking) => [
                $booking->starts_at->toImmutable()->setTimezone($timezone),
                $booking->ends_at->toImmutable()->setTimezone($timezone)->addMinutes($booking->service?->buffer_minutes ?? 0),
            ])
            ->all();

        $now = CarbonImmutable::now($timezone);
        $isToday = $localDate->isSameDay($now);

        $slots = [];
        foreach ($candidates as $start) {
            if ($isToday && $start->lt($now)) {
                continue;
            }

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
