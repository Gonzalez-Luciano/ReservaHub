<?php

namespace App\Actions\Employees;

use App\Models\EmployeeInvitation;

class RevokeInvitation
{
    public function handle(EmployeeInvitation $invitation): void
    {
        $invitation->delete();
    }
}
