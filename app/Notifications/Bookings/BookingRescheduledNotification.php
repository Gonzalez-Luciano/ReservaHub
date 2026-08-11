<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

class BookingRescheduledNotification extends BookingNotification
{
    public function __construct(
        Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
        public readonly NotificationAudience $audience,
    ) {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $before = $this->formatDateTime($this->previousStartsAt);
        $after = $this->formatDateTime();
        $service = $this->service()->name;

        if ($this->audience === NotificationAudience::Customer) {
            return (new MailMessage)
                ->subject("Reprogramamos tu reserva en {$this->booking->business->name}")
                ->greeting("Hola {$this->booking->customer->name},")
                ->line("Tu reserva de {$service} pasó del {$before} al {$after}.")
                ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
        }

        return (new MailMessage)
            ->subject('Se reprogramó una de tus reservas')
            ->greeting("Hola {$this->booking->employee->name},")
            ->line("La reserva de {$this->booking->customer->name} para {$service} pasó del {$before} al {$after}.")
            ->action('Ver la reserva', $this->actionUrl(NotificationAudience::Employee));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.rescheduled',
            'previous_starts_at' => $this->previousStartsAt->toIso8601String(),
        ];
    }
}
