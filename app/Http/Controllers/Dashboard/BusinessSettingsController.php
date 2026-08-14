<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Businesses\UpdateBusinessSettings;
use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateBusinessRequest;
use App\Models\Business;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function edit(): Response
    {
        $business = Business::current();

        $this->authorize('update', $business);

        return Inertia::render('Dashboard/Settings/Edit', [
            'business' => [
                'name' => $business->name,
                'slug' => $business->slug,
                'timezone' => $business->timezone,
                'currency' => $business->currency,
                'cancellation_hours' => $business->cancellation_hours,
            ],
            'currencies' => Currency::values(),
            'timezones' => DateTimeZone::listIdentifiers(),
            // Mismo patrón que PasswordResetLinkController: flash `status`
            // pasado explícito, no vía share() global.
            'status' => session('status'),
        ]);
    }

    public function update(UpdateBusinessRequest $request, UpdateBusinessSettings $action): RedirectResponse
    {
        $business = Business::current();

        $this->authorize('update', $business);

        $action->handle($business, $request->validated());

        return redirect()->route('dashboard.settings.edit')->with('status', 'Ajustes actualizados.');
    }
}
