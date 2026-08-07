<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $user->business_id === $schedule->business_id
            && in_array($user->role, Role::managers(), true);
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $this->update($user, $schedule);
    }
}
