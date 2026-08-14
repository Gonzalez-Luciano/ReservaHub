<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\BusinessHoliday;
use App\Models\User;

/**
 * Dos semánticas distintas a propósito:
 *
 * - `viewAny`/`create` no tienen recurso: autorizan contra el negocio actual
 *   del actor, que el middleware `business` ya dejó fijado.
 * - `delete` agrega la pertenencia del recurso. El global scope `BusinessScope`
 *   ya impide resolver un feriado ajeno por route-model binding (sale 404), así
 *   que esta comprobación es defensa en profundidad para llamadores sin
 *   contexto de negocio: consola, jobs, tests.
 */
class BusinessHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->business_id !== null
            && in_array($user->role, Role::managers(), true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, BusinessHoliday $holiday): bool
    {
        return $this->viewAny($user) && $user->business_id === $holiday->business_id;
    }
}
