<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * El servicio se resuelve sin el scope de negocio: hay caminos web, como cancelar
     * desde "mis reservas", que no ligan ningún Business al contenedor.
     */
    protected function service(): Service
    {
        return $this->booking->service()
            ->withoutGlobalScope(BusinessScope::class)
            ->firstOrFail();
    }

    protected function formatDateTime(?CarbonInterface $moment = null): string
    {
        return ($moment ?? $this->booking->starts_at)
            ->copy()
            ->setTimezone($this->booking->business->timezone)
            ->locale('es')
            ->isoFormat('ddd D MMM YYYY, HH:mm');
    }

    protected function actionUrl(NotificationAudience $audience): string
    {
        return $audience === NotificationAudience::Customer
            ? route('public.bookings.mine.index')
            : route('dashboard.bookings.show', $this->booking);
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePayload(): array
    {
        return [
            'booking_id' => $this->booking->id,
            'business_id' => $this->booking->business_id,
            'starts_at' => $this->booking->starts_at->toIso8601String(),
            'service' => $this->service()->name,
            'customer' => $this->booking->customer->name,
            'employee' => $this->booking->employee->name,
        ];
    }
}
