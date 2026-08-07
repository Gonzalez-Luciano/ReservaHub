<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Employees\SyncEmployeeServices;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\EmployeeServicesRequest;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class EmployeeServiceController extends Controller
{
    public function update(EmployeeServicesRequest $request, User $employee, SyncEmployeeServices $action): RedirectResponse
    {
        abort_unless(
            $employee->business_id === Business::current()->id && $employee->role === Role::Employee,
            404,
        );
        $this->authorize('update', $employee);

        $action->handle($employee, $request->validated('service_ids', []));

        return redirect()->route('dashboard.employees.index');
    }
}
