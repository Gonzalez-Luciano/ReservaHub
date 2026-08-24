<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'timeline' => $this->buildTimeline(),
        ]);
    }

    /**
     * Elige el primer negocio activo (orden determinista por nombre) que tenga
     * hoy al menos un empleado activo con horario activo, y proyecta su
     * ocupación del día. Un negocio activo sin empleado elegible hoy se
     * saltea, nunca aborta la búsqueda: por eso "timeline: null" solo puede
     * pasar cuando NINGÚN negocio activo califica.
     *
     * Esto no es un motor de disponibilidad: solo lista reservas existentes
     * no canceladas. Los huecos entre ellas son "sin reserva", no "libres" —
     * únicamente App\Services\AvailabilityService puede responder eso.
     */
    private function buildTimeline(): ?array
    {
        $businesses = Business::where('is_active', true)->orderBy('name')->get();

        foreach ($businesses as $business) {
            // Se liga el negocio candidato para que las consultas de modelos
            // multi-tenant (Schedule, Booking, Service) queden scopeadas a él.
            app()->instance(Business::class, $business);

            $now = CarbonImmutable::now($business->timezone);
            $dayOfWeek = DayOfWeek::from($now->dayOfWeek);

            $employee = $this->findEligibleEmployee($business, $dayOfWeek);

            if ($employee === null) {
                continue;
            }

            return $this->projectTimeline($business, $employee, $now);
        }

        return null;
    }

    /**
     * Primer empleado activo (orden determinista por id) con horario activo
     * para el día de la semana dado.
     */
    private function findEligibleEmployee(Business $business, DayOfWeek $dayOfWeek): ?User
    {
        return User::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (User $candidate) => Schedule::where('employee_id', $candidate->id)
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
                ->exists()
            );
    }

    /**
     * Proyección mínima de ocupación: solo starts_at/ends_at/duration_minutes
     * y el nombre del servicio de las reservas no canceladas del empleado
     * hoy, más el nombre del negocio y el nombre de pila del empleado. Nunca
     * identidad de cliente, id de reserva, estado ni ningún campo de pago.
     */
    private function projectTimeline(Business $business, User $employee, CarbonImmutable $now): array
    {
        $startOfDay = $now->startOfDay();
        $endOfDay = $startOfDay->addDay();

        $occupied = Booking::where('employee_id', $employee->id)
            ->where('status', '!=', BookingStatus::Cancelled)
            ->whereBetween('starts_at', [$startOfDay->utc(), $endOfDay->utc()])
            ->with('service')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $booking) => [
                'starts_at' => $booking->starts_at->setTimezone($business->timezone)->format('H:i'),
                'ends_at' => $booking->ends_at->setTimezone($business->timezone)->format('H:i'),
                'duration_minutes' => $booking->starts_at->diffInMinutes($booking->ends_at),
                'service_name' => $booking->service->name,
            ])
            ->values()
            ->all();

        return [
            'business_name' => $business->name,
            'employee_name' => (string) Str::of($employee->name)->before(' '),
            'date' => $now->format('Y-m-d'),
            'window' => ['start' => '09:00', 'end' => '18:00'],
            'occupied' => $occupied,
        ];
    }
}
