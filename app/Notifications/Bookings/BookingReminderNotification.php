<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Enums\ReminderType;
use App\Models\Booking;
use Illuminate\Notifications\Messages\MailMessage;

class BookingReminderNotification extends BookingNotification
{
    public function __construct(Booking $booking, public readonly ReminderType $type)
    {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $window = $this->type === ReminderType::TwentyFourHours ? '24 horas' : '2 horas';

        return (new MailMessage)
            ->subject("Recordatorio: tu turno en {$this->booking->business->name}")
            ->greeting("Hola {$this->booking->customer->name},")
            ->line("Te recordamos que faltan menos de {$window} para tu turno de {$this->service()->name}.")
            ->line("Es el {$this->formatDateTime()}, con {$this->booking->employee->name}.")
            ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.reminder',
            'reminder' => $this->type->value,
        ];
    }
}
