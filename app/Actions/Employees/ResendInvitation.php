<?php

namespace App\Actions\Employees;

use App\Models\EmployeeInvitation;
use App\Notifications\EmployeeInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ResendInvitation
{
    public function handle(EmployeeInvitation $invitation): EmployeeInvitation
    {
        $invitation->update([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)->notify(new EmployeeInvited($invitation));

        return $invitation;
    }
}
