<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function update(User $user, User $target): bool
    {
        return $user->business_id !== null
            && $user->business_id === $target->business_id
            && in_array($user->role, Role::managers(), true);
    }

    public function delete(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
