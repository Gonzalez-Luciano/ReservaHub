<?php

namespace App\Actions\Employees;

use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Notifications\EmployeeInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteEmployee
{
    public function handle(User $invitedBy, string $email, ?string $name): EmployeeInvitation
    {
        $invitation = EmployeeInvitation::create([
            'email' => $email,
            'name' => $name,
            'token' => Str::random(40),
            'invited_by_id' => $invitedBy->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)->notify(new EmployeeInvited($invitation));

        return $invitation;
    }
}
