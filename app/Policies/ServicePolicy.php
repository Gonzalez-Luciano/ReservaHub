<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->business_id !== null;
    }

    public function view(User $user, Service $service): bool
    {
        return $user->business_id === $service->business_id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->business_id === $service->business_id
            && in_array($user->role, Role::managers(), true);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }
}
