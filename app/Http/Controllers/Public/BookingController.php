<?php

namespace App\Http\Controllers\Public;

use App\Actions\Bookings\CreateBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BookingRequest;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function create(Business $business, AvailabilityService $availabilityService, Request $request): Response
    {
        $this->authorize('createByCustomer', Booking::class);

        return Inertia::render('Public/Business/Book', [
            'business' => $business->only(['id', 'name', 'slug']),
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes']),
            'employees' => $this->employeesFor($request),
            'slots' => $this->slotsFor($business, $availabilityService, $request),
        ]);
    }

    public function store(BookingRequest $request, Business $business, CreateBooking $action): RedirectResponse
    {
        $this->authorize('createByCustomer', Booking::class);

        $action->handle($business, [
            'customer_id' => $request->user()->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'web',
            'notes' => null,
        ], $request->user());

        // TODO(Task 12): once `Public\MyBookingsController` exists, redirect here to
        // `public.bookings.mine.index` instead — that route doesn't exist yet in this task.
        return redirect()->route('public.business.show', $business);
    }

    private function employeesFor(Request $request): array
    {
        $serviceId = $request->query('service_id');

        if (! $serviceId) {
            return [];
        }

        $service = Service::find($serviceId);

        return $service ? $service->employees()->get(['users.id', 'users.name'])->all() : [];
    }

    private function slotsFor(Business $business, AvailabilityService $availabilityService, Request $request): array
    {
        $employeeId = $request->query('employee_id');
        $serviceId = $request->query('service_id');
        $date = $request->query('date');

        if (! $employeeId || ! $serviceId || ! $date) {
            return [];
        }

        $employee = User::where('business_id', $business->id)->find($employeeId);
        $service = Service::find($serviceId);

        if (! $employee || ! $service) {
            return [];
        }

        return $availabilityService->getAvailableSlots($business, $service, $employee, CarbonImmutable::parse($date, $business->timezone));
    }
}
