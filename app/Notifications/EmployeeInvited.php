<?php

namespace App\Notifications;

use App\Models\EmployeeInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class EmployeeInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly EmployeeInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'invitations.accept',
            $this->invitation->expires_at,
            ['token' => $this->invitation->token],
        );

        return (new MailMessage)
            ->subject('Invitación para unirte a '.$this->invitation->business->name)
            ->line('Te invitaron a sumarte como empleado.')
            ->action('Aceptar invitación', $url)
            ->line('Este enlace vence en 7 días.');
    }
}
