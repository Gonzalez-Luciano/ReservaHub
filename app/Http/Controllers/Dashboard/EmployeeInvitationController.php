<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Employees\InviteEmployee;
use App\Actions\Employees\ResendInvitation;
use App\Actions\Employees\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\InviteEmployeeRequest;
use App\Models\EmployeeInvitation;
use Illuminate\Http\RedirectResponse;

class EmployeeInvitationController extends Controller
{
    public function store(InviteEmployeeRequest $request, InviteEmployee $action): RedirectResponse
    {
        $this->authorize('create', EmployeeInvitation::class);

        $action->handle($request->user(), $request->validated('email'), $request->validated('name'));

        return redirect()->route('dashboard.employees.index');
    }

    public function resend(EmployeeInvitation $invitation, ResendInvitation $action): RedirectResponse
    {
        $this->authorize('manage', $invitation);

        $action->handle($invitation);

        return redirect()->route('dashboard.employees.index');
    }

    public function destroy(EmployeeInvitation $invitation, RevokeInvitation $action): RedirectResponse
    {
        $this->authorize('manage', $invitation);

        $action->handle($invitation);

        return redirect()->route('dashboard.employees.index');
    }
}
