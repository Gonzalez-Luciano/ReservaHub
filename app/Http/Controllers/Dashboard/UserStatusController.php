<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Users\SetUserActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateUserStatusRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserStatusController extends Controller
{
    public function update(UpdateUserStatusRequest $request, User $user, SetUserActiveStatus $action): RedirectResponse
    {
        $this->authorize('setActiveStatus', $user);

        $result = $action->handle($user, $request->boolean('is_active'));

        return redirect()->route('dashboard.employees.index')
            ->with('status', $result['user']->is_active ? 'Usuario activado.' : 'Usuario desactivado.')
            ->with('future_bookings_count', $result['future_bookings_count']);
    }
}
