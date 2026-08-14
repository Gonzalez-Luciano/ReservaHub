<?php

namespace App\Actions\Holidays;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Models\Scopes\BusinessScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CreateBusinessHoliday
{
    private const PREVIEW_LIMIT = 5;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException cuando el rango se superpone con otro feriado
     *                             o con reservas activas.
     */
    public function handle(Business $business, array $data): BusinessHoliday
    {
        $startsOn = (string) $data['starts_on'];
        $endsOn = (string) $data['ends_on'];

        $this->assertNoHolidayOverlap($business, $startsOn, $endsOn);
        $this->assertNoBookingOverlap($business, $startsOn, $endsOn);

        return BusinessHoliday::create([
            'business_id' => $business->id,
            'name' => $data['name'],
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    private function assertNoHolidayOverlap(Business $business, string $startsOn, string $endsOn): void
    {
        $overlaps = BusinessHoliday::query()
            ->withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_on' => 'Ya existe un feriado que se superpone con ese rango.',
            ]);
        }
    }

    private function assertNoBookingOverlap(Business $business, string $startsOn, string $endsOn): void
    {
        $timezone = $business->timezone;

        // Rango local inclusivo -> intervalo UTC semiabierto [inicio, fin).
        $holidayStartUtc = CarbonImmutable::parse($startsOn, $timezone)->startOfDay()->utc();
        $holidayEndUtc = CarbonImmutable::parse($endsOn, $timezone)->startOfDay()->addDay()->utc();

        /** @var Collection<int, Booking> $conflicts */
        $conflicts = Booking::query()
            ->withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            // Solapamiento de intervalos: alcanza con que la reserva pise el
            // rango, aunque haya empezado antes de que el feriado arranque.
            ->where('starts_at', '<', $holidayEndUtc)
            ->where('ends_at', '>', $holidayStartUtc)
            ->orderBy('starts_at')
            ->with(['service:id,name', 'employee:id,name'])
            ->get();

        if ($conflicts->isEmpty()) {
            return;
        }

        $total = $conflicts->count();
        $plural = $total === 1 ? 'reserva activa' : 'reservas activas';

        throw ValidationException::withMessages([
            'starts_on' => "No podés crear el feriado: hay {$total} {$plural} en ese rango. Cancelalas o reprogramalas primero.",
            // `withMessages()` solo transporta strings: la vista previa son
            // líneas ya formateadas, no objetos. Sin datos del cliente.
            'bookings_preview' => $conflicts
                ->take(self::PREVIEW_LIMIT)
                ->map(fn (Booking $booking) => sprintf(
                    '%s — %s — %s',
                    $booking->starts_at->toImmutable()->setTimezone($timezone)->format('d/m/Y H:i'),
                    $booking->service?->name ?? 'Servicio',
                    $booking->employee?->name ?? 'Empleado',
                ))
                ->values()
                ->all(),
        ]);
    }
}
