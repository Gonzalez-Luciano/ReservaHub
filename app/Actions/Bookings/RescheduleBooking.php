<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Events\BookingRescheduled;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleBooking
{
    public function __construct(private readonly AvailabilityService $availabilityService) {}

    /**
     * @param  array{starts_at: string}  $data
     */
    public function handle(Booking $booking, array $data, User $actingUser): Booking
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => 'Esta reserva no puede reprogramarse desde su estado actual.']);
        }

        $business = $booking->business;
        app()->instance(Business::class, $business);

        $service = $booking->service;
        $employee = $booking->employee;
        $previousStartsAt = CarbonImmutable::parse($booking->starts_at)->setTimezone($business->timezone);
        $newStart = CarbonImmutable::parse($data['starts_at'])->setTimezone($business->timezone);
        $newEnd = $newStart->addMinutes($service->duration_minutes);

        $booking = DB::transaction(function () use ($business, $service, $employee, $booking, $newStart, $newEnd, $previousStartsAt, $actingUser) {
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['booking-employee-'.$employee->id]);

            if ($newStart->lt(CarbonImmutable::now($business->timezone))) {
                throw ValidationException::withMessages(['starts_at' => 'No se puede reprogramar a un horario que ya pasó.']);
            }

            $available = collect($this->availabilityService->getAvailableSlots($business, $service, $employee, $newStart, excludeBookingId: $booking->id))
                ->contains(fn (array $slot) => $slot['starts_at']->equalTo($newStart));

            if (! $available) {
                throw ValidationException::withMessages(['starts_at' => 'Ese horario ya no está disponible.']);
            }

            $booking->update(['starts_at' => $newStart, 'ends_at' => $newEnd]);

            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => $booking->status,
                'to_status' => $booking->status,
                'changed_by' => $actingUser->id,
                'notes' => "Reprogramado de {$previousStartsAt->format('Y-m-d H:i')} a {$newStart->format('Y-m-d H:i')}.",
            ]);

            return $booking->fresh();
        });

        event(new BookingRescheduled($booking, $previousStartsAt));

        return $booking;
    }
}
