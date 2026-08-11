<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class BookingCancelledNotification extends BookingNotification
{
    public function __construct(
        Booking $booking,
        public readonly User $cancelledBy,
        public readonly NotificationAudience $audience,
    ) {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->formatDateTime();
        $service = $this->service()->name;
        $business = $this->booking->business->name;

        if ($this->audience === NotificationAudience::Customer) {
            $line = $this->cancelledByCustomer()
                ? "Cancelaste tu reserva de {$service} del {$when}."
                : "{$business} canceló tu reserva de {$service} del {$when}.";

            return (new MailMessage)
                ->subject("Se canceló tu reserva en {$business}")
                ->greeting("Hola {$this->booking->customer->name},")
                ->line($line)
                ->action('Ver mis reservas', $this->actionUrl(NotificationAudience::Customer));
        }

        $line = $this->cancelledByCustomer()
            ? "{$this->booking->customer->name} canceló su reserva de {$service} del {$when}."
            : "Se canceló la reserva de {$this->booking->customer->name} para {$service} del {$when}.";

        return (new MailMessage)
            ->subject('Se canceló una de tus reservas')
            ->greeting("Hola {$this->booking->employee->name},")
            ->line($line)
            ->action('Ver la agenda', $this->actionUrl(NotificationAudience::Employee));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.cancelled',
            'cancelled_by' => $this->cancelledBy->id,
            'cancelled_by_customer' => $this->cancelledByCustomer(),
        ];
    }

    private function cancelledByCustomer(): bool
    {
        return $this->cancelledBy->id === $this->booking->customer_id;
    }
}
