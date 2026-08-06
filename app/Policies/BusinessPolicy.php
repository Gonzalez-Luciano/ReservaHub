<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;

/**
 * Governs management of business *settings* (Owner/Admin only via
 * Role::managers()). This is narrower than, and distinct from,
 * EnsureBusinessContext's "has business context" gate, which also admits
 * Employee to the dashboard shell — that middleware answers "can this role
 * get some kind of dashboard access at all", while this policy answers
 * "can this role change business settings". Employees are intentionally
 * denied here even though they pass the middleware.
 */
class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $user->business_id !== null
            && $user->business_id === $business->id
            && in_array($user->role, Role::managers(), true);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->view($user, $business);
    }
}
