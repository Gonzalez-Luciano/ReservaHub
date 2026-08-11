<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use Illuminate\Notifications\Messages\MailMessage;

class BookingConfirmedNotification extends BookingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tu reserva en {$this->booking->business->name} está confirmada")
            ->greeting("Hola {$this->booking->customer->name},")
            ->line("Ya está confirmada tu reserva de {$this->service()->name} para el {$this->formatDateTime()}.")
            ->line("Te atiende {$this->booking->employee->name}.")
            ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + ['type' => 'booking.confirmed'];
    }
}
