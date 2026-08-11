<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBooking
{
    public function __construct(private readonly AvailabilityService $availabilityService) {}

    /**
     * @param  array{customer_id: int, employee_id: int, service_id: int, starts_at: string, source: string, notes?: string|null}  $data
     */
    public function handle(Business $business, array $data, User $actingUser): Booking
    {
        app()->instance(Business::class, $business);

        $service = Service::findOrFail($data['service_id']);
        $employee = User::where('business_id', $business->id)->where('role', Role::Employee)->findOrFail($data['employee_id']);
        $customer = User::where('role', Role::Customer)->findOrFail($data['customer_id']);

        if ($service->business_id !== $business->id) {
            throw ValidationException::withMessages(['service_id' => 'El servicio no pertenece a este negocio.']);
        }

        if (! $service->is_active) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio no está disponible.']);
        }

        if (! $employee->is_active) {
            throw ValidationException::withMessages(['employee_id' => 'Este empleado no está disponible.']);
        }

        if (! $service->employees()->whereKey($employee->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Ese empleado no realiza este servicio.']);
        }

        $startsAt = CarbonImmutable::parse($data['starts_at'])->setTimezone($business->timezone);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        $booking = DB::transaction(function () use ($business, $service, $employee, $customer, $startsAt, $endsAt, $data, $actingUser) {
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['booking-employee-'.$employee->id]);

            if ($startsAt->lt(CarbonImmutable::now($business->timezone))) {
                throw ValidationException::withMessages(['starts_at' => 'No se puede reservar en un horario que ya pasó.']);
            }

            $available = collect($this->availabilityService->getAvailableSlots($business, $service, $employee, $startsAt))
                ->contains(fn (array $slot) => $slot['starts_at']->equalTo($startsAt));

            if (! $available) {
                throw ValidationException::withMessages(['starts_at' => 'Ese horario ya no está disponible.']);
            }

            $status = $service->deposit_amount > 0 ? BookingStatus::Pending : BookingStatus::Confirmed;

            $booking = Booking::create([
                'customer_id' => $customer->id,
                'employee_id' => $employee->id,
                'service_id' => $service->id,
                // Persisted in UTC: the `datetime` cast writes a Carbon value's
                // *current* wall-clock digits verbatim and reads them back
                // assuming UTC, so storing a business-timezone-labeled Carbon
                // here would round-trip to the wrong instant on the next fetch.
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'status' => $status,
                'price' => $service->price,
                'deposit_amount' => $service->deposit_amount,
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'],
            ]);

            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => null,
                'to_status' => $status,
                'changed_by' => $actingUser->id,
            ]);

            return $booking;
        });

        event(new BookingCreated($booking));

        return $booking;
    }
}
