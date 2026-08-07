<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Services\CreateService;
use App\Actions\Services\DeleteService;
use App\Actions\Services\UpdateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ServiceRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Service::class);

        return Inertia::render('Dashboard/Services/Index', [
            'services' => Service::orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Service::class);

        return Inertia::render('Dashboard/Services/Form');
    }

    public function store(ServiceRequest $request, CreateService $action): RedirectResponse
    {
        $this->authorize('create', Service::class);

        $action->handle($request->validated());

        return redirect()->route('dashboard.services.index');
    }

    public function edit(Service $service): Response
    {
        $this->authorize('update', $service);

        return Inertia::render('Dashboard/Services/Form', ['service' => $service]);
    }

    public function update(ServiceRequest $request, Service $service, UpdateService $action): RedirectResponse
    {
        $this->authorize('update', $service);

        $action->handle($service, $request->validated());

        return redirect()->route('dashboard.services.index');
    }

    public function destroy(Service $service, DeleteService $action): RedirectResponse
    {
        $this->authorize('delete', $service);

        $action->handle($service);

        return redirect()->route('dashboard.services.index');
    }
}
