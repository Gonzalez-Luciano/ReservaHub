<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\EmployeeInvitation;
use App\Models\User;

class EmployeeInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function manage(User $user, EmployeeInvitation $invitation): bool
    {
        return $user->business_id === $invitation->business_id
            && in_array($user->role, Role::managers(), true);
    }
}
