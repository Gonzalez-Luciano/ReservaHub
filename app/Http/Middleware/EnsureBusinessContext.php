<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasBusiness() || ! in_array($user->role, [Role::Owner, Role::Admin, Role::Employee], true)) {
            abort(403);
        }

        app()->instance(Business::class, $user->business);

        return $next($request);
    }
}
