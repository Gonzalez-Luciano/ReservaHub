<?php

namespace App\Actions\Employees;

use App\Models\Service;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SyncEmployeeServices
{
    /**
     * @param  array<int, int>  $serviceIds
     */
    public function handle(User $employee, array $serviceIds): void
    {
        $services = Service::whereIn('id', $serviceIds)->get();

        if ($services->count() !== count(array_unique($serviceIds))) {
            throw ValidationException::withMessages([
                'service_ids' => 'Uno o más servicios no pertenecen a esta empresa.',
            ]);
        }

        $employee->services()->sync($services->pluck('id'));
    }
}
