<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $business = Business::current();

        abort_if($business === null, 500);

        $user = request()->user();
        $employeeId = $user->role === Role::Employee ? $user->id : null;

        $now = CarbonImmutable::now($business->timezone);
        $startOfToday = $now->startOfDay();
        $endOfToday = $startOfToday->addDay();

        $today = Booking::whereBetween('starts_at', [$startOfToday->utc(), $endOfToday->utc()])
            ->when($employeeId, fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->with(['service:id,name', 'employee:id,name', 'customer:id,name'])
            ->orderBy('starts_at')
            ->orderBy('ends_at')
            ->orderBy('id')
            ->get();

        $todayByStatus = $today->countBy(fn (Booking $booking) => $booking->status->value);

        // `awaiting_deposit` (la métrica) es el total de pendientes, sin
        // restricción de día: pending solo existe cuando el servicio pide
        // seña (CreateBooking:67), así que no hay reservas "por confirmar"
        // fuera de esta cola.
        $pending = Booking::where('status', BookingStatus::Pending)
            ->when($employeeId, fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->with(['service:id,name', 'employee:id,name', 'customer:id,name'])
            ->orderBy('payment_expires_at')
            ->orderBy('id')
            ->get();

        // La condición `> now()` no es opcional: sin ella, una reserva ya
        // vencida (payment_expires_at en el pasado) cuenta como "vence
        // pronto" durante la ventana entre el vencimiento y el paso del
        // scheduler (`bookings:expire-unpaid`) que la cancela.
        $expiringCutoff = now()->addMinutes(15);
        $expiringSoon = $pending->filter(
            fn (Booking $booking) => $booking->payment_expires_at !== null
                && $booking->payment_expires_at->isFuture()
                && $booking->payment_expires_at->lessThanOrEqualTo($expiringCutoff)
        )->values();
        $expiringSoonIds = $expiringSoon->pluck('id')->all();

        // La cola visible clasifica cada reserva UNA sola vez: expiring_soon
        // se resuelve primero y awaiting_deposit recoge únicamente las
        // pendientes que no quedaron ahí. Las métricas sí pueden solaparse
        // (awaiting_deposit es el total de pending), pero la lista no.
        $awaitingDepositOnly = $pending->reject(
            fn (Booking $booking) => in_array($booking->id, $expiringSoonIds, true)
        )->values();

        $upcomingStart = $endOfToday;
        $upcomingEnd = $endOfToday->addDays(7);

        $upcoming7d = Booking::where('status', BookingStatus::Confirmed)
            ->whereBetween('starts_at', [$upcomingStart->utc(), $upcomingEnd->utc()])
            ->when($employeeId, fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->count();

        // `->all()` before merging: both operands are Eloquent Collections
        // once mapped, and Eloquent Collection::merge() expects Model
        // instances (it keys by getKey()) — these items are plain arrays.
        $attention = array_merge(
            $expiringSoon->map(fn (Booking $booking) => $this->presentAttention($booking, 'expiring_soon', $business))->all(),
            $awaitingDepositOnly->map(fn (Booking $booking) => $this->presentAttention($booking, 'awaiting_deposit', $business))->all(),
        );

        return Inertia::render('Dashboard/Index', [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'timezone' => $business->timezone,
                'currency' => $business->currency,
            ],
            'metrics' => [
                'today_total' => $today->count(),
                'today_by_status' => $todayByStatus,
                'awaiting_deposit' => $pending->count(),
                'expiring_soon' => $expiringSoon->count(),
                'upcoming_7d' => $upcoming7d,
            ],
            'today' => $today->map(fn (Booking $booking) => $this->presentToday($booking, $business))->values(),
            'attention' => $attention,
        ]);
    }

    /**
     * `starts_at`/`ends_at`/`payment_expires_at` van como `H:i` en la zona
     * del negocio, no como instantes ISO en UTC: el cliente (DayRail) los
     * parsea con un split de string, sin construir un `Date`, así que no hay
     * ninguna conversión de zona horaria pendiente del lado del navegador.
     * Mismo patrón que `HomeController::projectTimeline`.
     */
    private function presentToday(Booking $booking, Business $business): array
    {
        $startsAt = $booking->starts_at;
        $endsAt = $booking->ends_at;

        return [
            'id' => $booking->id,
            'starts_at' => $startsAt->copy()->setTimezone($business->timezone)->format('H:i'),
            'ends_at' => $endsAt->copy()->setTimezone($business->timezone)->format('H:i'),
            'duration_minutes' => $startsAt->diffInMinutes($endsAt),
            'status' => $booking->status->value,
            'service_name' => $booking->service->name,
            'employee_name' => $booking->employee->name,
            'customer_name' => $booking->customer->name,
            'deposit_amount' => $booking->deposit_amount,
            'payment_expires_at' => $booking->payment_expires_at?->copy()->setTimezone($business->timezone)->format('H:i'),
        ];
    }

    private function presentAttention(Booking $booking, string $kind, Business $business): array
    {
        return [
            'id' => $booking->id,
            'kind' => $kind,
            'starts_at' => $booking->starts_at->copy()->setTimezone($business->timezone)->format('H:i'),
            'status' => $booking->status->value,
            'service_name' => $booking->service->name,
            'employee_name' => $booking->employee->name,
            'customer_name' => $booking->customer->name,
            'deposit_amount' => $booking->deposit_amount,
            'payment_expires_at' => $booking->payment_expires_at?->copy()->setTimezone($business->timezone)->format('H:i'),
        ];
    }
}
