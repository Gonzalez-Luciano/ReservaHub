<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', EmployeeInvitation::class);

        $business = Business::current();

        return Inertia::render('Dashboard/Employees/Index', [
            'employees' => User::query()
                ->where('business_id', $business->id)
                ->where('role', Role::Employee)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_active']),
            'invitations' => EmployeeInvitation::pending()
                ->orderBy('created_at', 'desc')
                ->get(['id', 'email', 'name', 'expires_at']),
        ]);
    }
}
