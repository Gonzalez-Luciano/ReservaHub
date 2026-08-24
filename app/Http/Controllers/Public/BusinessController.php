<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function index(): Response
    {
        // withCount/withMin fold both aggregates into the single businesses
        // query as correlated subqueries — one round trip no matter how many
        // businesses are listed. Each subquery reaches into `services`
        // without the business the visitor is scoped to (there isn't one
        // here), so it must opt out of BusinessScope itself and re-apply
        // "active" by hand.
        $activeServices = function (Builder $query): void {
            $query->withoutGlobalScope(BusinessScope::class)->where('is_active', true);
        };

        $businesses = Business::where('is_active', true)
            ->select(['id', 'name', 'slug', 'currency'])
            ->withCount(['services as services_count' => $activeServices])
            ->withMin(['services as lowest_price' => $activeServices], 'price')
            ->orderBy('name')
            ->get();

        return Inertia::render('Public/Business/Index', [
            'businesses' => $businesses,
        ]);
    }

    public function show(Business $business): Response
    {
        return Inertia::render('Public/Business/Show', [
            'business' => $business->only(['id', 'name', 'slug', 'currency']),
            'services' => Service::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'duration_minutes', 'price', 'deposit_amount']),
        ]);
    }
}
