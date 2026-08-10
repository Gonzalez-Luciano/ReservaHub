<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindPublicBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $business = $route->parameter('business');

        if (! $business instanceof Business) {
            $business = Business::where('slug', $business)->firstOrFail();
            $route->setParameter('business', $business);
        }

        app()->instance(Business::class, $business);

        return $next($request);
    }
}
