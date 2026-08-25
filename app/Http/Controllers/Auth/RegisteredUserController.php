<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterBusinessOwner;
use App\Actions\Auth\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Support\HomeRoute;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = $request->validated('account_type') === 'business'
            ? (new RegisterBusinessOwner)->handle(
                name: $request->validated('name'),
                email: $request->validated('email'),
                password: $request->validated('password'),
                businessName: $request->validated('business_name'),
            )
            : (new RegisterCustomer)->handle(
                name: $request->validated('name'),
                email: $request->validated('email'),
                password: $request->validated('password'),
            );

        event(new Registered($user));

        Auth::login($user);

        return redirect(HomeRoute::for($user));
    }
}
