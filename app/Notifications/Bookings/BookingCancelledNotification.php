<?php

namespace App\Notifications\Bookings;

use App\Enums\CancellationReason;
use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class BookingCancelledNotification extends BookingNotification
{
    public function __construct(
        Booking $booking,
        public readonly ?User $cancelledBy,
        public readonly NotificationAudience $audience,
        public readonly CancellationReason $reason = CancellationReason::Requested,
    ) {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->formatDateTime();
        $service = $this->service()->name;
        $business = $this->booking->business->name;
        $expired = $this->reason === CancellationReason::PaymentWindowExpired;

        if ($this->audience === NotificationAudience::Customer) {
            $line = match (true) {
                $expired => "Se canceló tu reserva de {$service} del {$when} porque no se registró el pago de la seña dentro del plazo.",
                $this->cancelledByCustomer() => "Cancelaste tu reserva de {$service} del {$when}.",
                default => "{$business} canceló tu reserva de {$service} del {$when}.",
            };

            return (new MailMessage)
                ->subject("Se canceló tu reserva en {$business}")
                ->greeting("Hola {$this->booking->customer->name},")
                ->line($line)
                ->action('Ver mis reservas', $this->actionUrl(NotificationAudience::Customer));
        }

        $line = match (true) {
            $expired => "Se canceló automáticamente la reserva de {$this->booking->customer->name} para {$service} del {$when}: la seña no se pagó dentro del plazo.",
            $this->cancelledByCustomer() => "{$this->booking->customer->name} canceló su reserva de {$service} del {$when}.",
            default => "Se canceló la reserva de {$this->booking->customer->name} para {$service} del {$when}.",
        };

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
            'cancelled_by' => $this->cancelledBy?->id,
            'cancelled_by_customer' => $this->cancelledByCustomer(),
            'reason' => $this->reason->value,
        ];
    }

    private function cancelledByCustomer(): bool
    {
        return $this->cancelledBy?->id === $this->booking->customer_id;
    }
}
