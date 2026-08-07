<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\TimeOff;
use App\Models\User;

class TimeOffPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function delete(User $user, TimeOff $timeOff): bool
    {
        return $user->business_id === $timeOff->business_id
            && in_array($user->role, Role::managers(), true);
    }
}
