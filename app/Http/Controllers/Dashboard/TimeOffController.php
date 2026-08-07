<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Schedules\CreateTimeOff;
use App\Actions\Schedules\DeleteTimeOff;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\TimeOffRequest;
use App\Models\Business;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TimeOffController extends Controller
{
    public function store(TimeOffRequest $request, User $employee, CreateTimeOff $action): RedirectResponse
    {
        abort_unless(
            $employee->business_id === Business::current()->id && $employee->role === Role::Employee,
            404,
        );
        $this->authorize('create', TimeOff::class);

        $action->handle([...$request->validated(), 'employee_id' => $employee->id]);

        return redirect()->route('dashboard.employees.schedule.index', $employee);
    }

    public function destroy(TimeOff $timeOff, DeleteTimeOff $action): RedirectResponse
    {
        $this->authorize('delete', $timeOff);

        $employeeId = $timeOff->employee_id;
        $action->handle($timeOff);

        return redirect()->route('dashboard.employees.schedule.index', $employeeId);
    }
}
