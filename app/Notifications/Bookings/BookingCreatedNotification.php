<?php

namespace App\Notifications\Bookings;

use App\Enums\BookingStatus;
use App\Enums\NotificationAudience;
use App\Models\Booking;
use Illuminate\Notifications\Messages\MailMessage;

class BookingCreatedNotification extends BookingNotification
{
    public function __construct(Booking $booking, public readonly NotificationAudience $audience)
    {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->audience === NotificationAudience::Customer
            ? $this->customerMail()
            : $this->employeeMail();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.created',
            'status' => $this->booking->status->value,
        ];
    }

    private function customerMail(): MailMessage
    {
        $when = $this->formatDateTime();
        $service = $this->service()->name;
        $business = $this->booking->business->name;

        if ($this->booking->status === BookingStatus::Pending) {
            return (new MailMessage)
                ->subject("Tu reserva en {$business} quedó pendiente de pago")
                ->greeting("Hola {$this->booking->customer->name},")
                ->line("Reservamos {$service} para el {$when}.")
                ->line('Para confirmarla necesitamos que abones la seña.')
                ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer))
                ->line('Si no se abona la seña, el turno puede liberarse.');
        }

        return (new MailMessage)
            ->subject("Tu reserva en {$business} está confirmada")
            ->greeting("Hola {$this->booking->customer->name},")
            ->line("Confirmamos {$service} para el {$when}.")
            ->line("Te atiende {$this->booking->employee->name}.")
            ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
    }

    private function employeeMail(): MailMessage
    {
        return (new MailMessage)
            ->subject('Tenés una reserva nueva')
            ->greeting("Hola {$this->booking->employee->name},")
            ->line("{$this->booking->customer->name} reservó {$this->service()->name} para el {$this->formatDateTime()}.")
            ->action('Ver la reserva', $this->actionUrl(NotificationAudience::Employee));
    }
}
