<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
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

            // La ventana nunca se extiende. Con un intento vivo, adelantarla por
            // debajo de la expiración del pago dejaría reserva, pago y proveedor
            // con ventanas contradictorias: `expire-unpaid` no cancelaría (hay un
            // `pending`) y el proveedor seguiría vivo pasada la fecha límite.
            $paymentExpiresAt = $booking->payment_expires_at?->toImmutable();
            $newDeadline = null;

            if ($paymentExpiresAt !== null) {
                $newDeadline = $newStart->utc()->lessThan($paymentExpiresAt)
                    ? $newStart->utc()
                    : $paymentExpiresAt;

                $livePayment = $booking->payments()->where('status', PaymentStatus::Pending)->first();

                if ($livePayment !== null && $newDeadline->lessThan($livePayment->expires_at->toImmutable())) {
                    throw ValidationException::withMessages([
                        'starts_at' => 'Hay un pago de seña en curso; esperá a que venza o cancelalo antes de reprogramar a un horario anterior.',
                    ]);
                }
            }

            // Persistido en UTC: el cast `datetime` escribe los dígitos de reloj
            // *actuales* del Carbon tal cual y los relee asumiendo UTC, así que
            // guardar acá un Carbon con display en horario de negocio volvería
            // con el instante equivocado en la próxima lectura.
            $booking->update([
                'starts_at' => $newStart->utc(),
                'ends_at' => $newEnd->utc(),
                'payment_expires_at' => $paymentExpiresAt === null ? null : $newDeadline,
            ]);

            // Los recordatorios ya reclamados apuntaban al horario anterior; sin esto,
            // el comando nunca volvería a evaluar la reserva para el horario nuevo.
            $booking->reminders()->delete();

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
