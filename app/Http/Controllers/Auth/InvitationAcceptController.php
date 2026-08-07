<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Employees\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\EmployeeInvitation;
use App\Models\Scopes\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitationAcceptController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->findAcceptable($token);

        if (! $invitation) {
            return Inertia::render('Invitations/Unavailable');
        }

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'email' => $invitation->email,
            'businessName' => $invitation->business->name,
        ]);
    }

    public function store(AcceptInvitationRequest $request, AcceptInvitation $action): Response|RedirectResponse
    {
        $invitation = $this->findAcceptable($request->validated('token'));

        if (! $invitation) {
            return Inertia::render('Invitations/Unavailable');
        }

        $user = $action->handle($invitation, $request->validated('name'), $request->validated('password'));

        Auth::login($user);

        return redirect('/dashboard');
    }

    private function findAcceptable(string $token): ?EmployeeInvitation
    {
        $invitation = EmployeeInvitation::withoutGlobalScope(BusinessScope::class)
            ->where('token', $token)
            ->first();

        if (! $invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            return null;
        }

        return $invitation;
    }
}
