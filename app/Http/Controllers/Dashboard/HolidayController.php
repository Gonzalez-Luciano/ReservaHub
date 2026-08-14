<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Holidays\CreateBusinessHoliday;
use App\Actions\Holidays\DeleteBusinessHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreHolidayRequest;
use App\Models\Business;
use App\Models\BusinessHoliday;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', BusinessHoliday::class);

        return Inertia::render('Dashboard/Holidays/Index', [
            'holidays' => BusinessHoliday::orderBy('starts_on')->get(['id', 'name', 'starts_on', 'ends_on']),
            // Mismo patrón que PasswordResetLinkController: flash `status`
            // pasado explícito, no vía share() global.
            'status' => session('status'),
        ]);
    }

    public function store(StoreHolidayRequest $request, CreateBusinessHoliday $action): RedirectResponse
    {
        $this->authorize('create', BusinessHoliday::class);

        $action->handle(Business::current(), $request->validated());

        return redirect()->route('dashboard.holidays.index')->with('status', 'Feriado creado.');
    }

    public function destroy(BusinessHoliday $holiday, DeleteBusinessHoliday $action): RedirectResponse
    {
        $this->authorize('delete', $holiday);

        $action->handle($holiday);

        return redirect()->route('dashboard.holidays.index')->with('status', 'Feriado eliminado.');
    }
}
