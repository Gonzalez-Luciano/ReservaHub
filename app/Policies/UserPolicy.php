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

    /**
     * Activar/desactivar a otro usuario del mismo negocio.
     *
     * Decide solo por identidad y rol. El invariante del último owner activo
     * vive en la Action: depende del estado actual de los datos y necesita lock.
     */
    public function setActiveStatus(User $user, User $target): bool
    {
        if ($user->business_id === null || $user->business_id !== $target->business_id) {
            return false;
        }

        if ($user->id === $target->id) {
            return false;
        }

        return match ($user->role) {
            Role::Owner => true,
            Role::Admin => $target->role !== Role::Owner,
            default => false,
        };
    }
}
