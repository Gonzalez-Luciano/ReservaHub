<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\Service;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', EmployeeInvitation::class);

        $business = Business::current();

        $employees = User::query()
            ->where('business_id', $business->id)
            ->where('role', Role::Employee)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active'])
            ->load('services:id')
            ->map(fn (User $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'is_active' => $employee->is_active,
                'service_ids' => $employee->services->pluck('id'),
            ]);

        // La fecha de vencimiento se manda ya formateada en la zona del negocio
        // (mismo criterio que `BookingController::presentBooking`): sin esto
        // la tabla mostraba el timestamp ISO crudo del cast `datetime`.
        $invitations = EmployeeInvitation::pending()->orderBy('created_at', 'desc')->get(['id', 'email', 'name', 'expires_at'])
            ->map(fn (EmployeeInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'expires_at_display' => $invitation->expires_at->copy()->setTimezone($business->timezone)->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Dashboard/Employees/Index', [
            'employees' => $employees,
            'invitations' => $invitations,
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'status' => session('status'),
            'future_bookings_count' => session('future_bookings_count'),
        ]);
    }
}
