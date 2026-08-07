<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Schedules\AddScheduleBreak;
use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\DeleteSchedule;
use App\Actions\Schedules\DeleteScheduleBreak;
use App\Actions\Schedules\UpdateSchedule;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ScheduleBreakRequest;
use App\Http\Requests\Dashboard\ScheduleRequest;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    public function index(User $employee): Response
    {
        $this->authorizeEmployee($employee);
        $this->authorize('viewAny', Schedule::class);

        return Inertia::render('Dashboard/Employees/Schedule', [
            'employee' => $employee->only(['id', 'name', 'email']),
            'schedules' => $employee->schedules()->with('breaks')->orderBy('day_of_week')->get(),
            'timeOffs' => $employee->timeOffs()->orderBy('starts_at')->get(),
        ]);
    }

    public function store(ScheduleRequest $request, User $employee, CreateSchedule $action): RedirectResponse
    {
        $this->authorizeEmployee($employee);
        $this->authorize('create', Schedule::class);

        $action->handle([...$request->validated(), 'employee_id' => $employee->id]);

        return redirect()->route('dashboard.employees.schedule.index', $employee);
    }

    public function update(ScheduleRequest $request, Schedule $schedule, UpdateSchedule $action): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $action->handle($schedule, $request->validated());

        return redirect()->route('dashboard.employees.schedule.index', $schedule->employee_id);
    }

    public function destroy(Schedule $schedule, DeleteSchedule $action): RedirectResponse
    {
        $this->authorize('delete', $schedule);

        $employeeId = $schedule->employee_id;
        $action->handle($schedule);

        return redirect()->route('dashboard.employees.schedule.index', $employeeId);
    }

    public function storeBreak(ScheduleBreakRequest $request, Schedule $schedule, AddScheduleBreak $action): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $action->handle($schedule, $request->validated());

        return redirect()->route('dashboard.employees.schedule.index', $schedule->employee_id);
    }

    public function destroyBreak(ScheduleBreak $scheduleBreak, DeleteScheduleBreak $action): RedirectResponse
    {
        // ScheduleBreak has no BelongsToBusiness scope of its own — authorizing via
        // its parent schedule is what actually blocks cross-business access here.
        abort_unless($scheduleBreak->schedule, 404);
        $this->authorize('update', $scheduleBreak->schedule);

        $employeeId = $scheduleBreak->schedule->employee_id;
        $action->handle($scheduleBreak);

        return redirect()->route('dashboard.employees.schedule.index', $employeeId);
    }

    private function authorizeEmployee(User $employee): void
    {
        abort_unless(
            $employee->business_id === Business::current()->id && $employee->role === Role::Employee,
            404,
        );
    }
}
