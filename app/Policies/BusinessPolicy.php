<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $user->business_id === $business->id
            && in_array($user->role, [Role::Owner, Role::Admin], true);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->view($user, $business);
    }
}
