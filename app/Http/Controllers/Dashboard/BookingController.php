<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CompleteBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\MarkNoShow;
use App\Actions\Bookings\RescheduleBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\BookingRequest;
use App\Http\Requests\Dashboard\RescheduleBookingRequest;
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
    public function index(): Response
    {
        $this->authorize('viewAny', [Booking::class, Business::current()]);

        return Inertia::render('Dashboard/Bookings/Index', [
            'bookings' => Booking::with(['customer:id,name,email', 'employee:id,name', 'service:id,name'])
                ->orderByDesc('starts_at')
                ->get(),
        ]);
    }

    public function create(AvailabilityService $availabilityService, Request $request): Response
    {
        $this->authorize('createByStaff', [Booking::class, Business::current()]);

        return Inertia::render('Dashboard/Bookings/Form', [
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes']),
            'employees' => User::where('business_id', Business::current()->id)->where('role', 'employee')->orderBy('name')->get(['id', 'name']),
            'slots' => $this->slotsFor($availabilityService, $request),
        ]);
    }

    public function store(BookingRequest $request, CreateBooking $action): RedirectResponse
    {
        $customer = User::where('email', $request->validated('customer_email'))->firstOrFail();

        $action->handle(Business::current(), [
            'customer_id' => $customer->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'admin',
            'notes' => $request->validated('notes'),
        ], $request->user());

        return redirect()->route('dashboard.bookings.index');
    }

    public function show(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        return Inertia::render('Dashboard/Bookings/Show', [
            'booking' => $booking->load(['customer:id,name,email', 'employee:id,name', 'service:id,name', 'statusHistories.changedBy:id,name']),
        ]);
    }

    public function confirm(Booking $booking, ConfirmBooking $action): RedirectResponse
    {
        $this->authorize('confirm', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function cancel(Booking $booking, CancelBooking $action): RedirectResponse
    {
        $this->authorize('cancel', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function complete(Booking $booking, CompleteBooking $action): RedirectResponse
    {
        $this->authorize('complete', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function noShow(Booking $booking, MarkNoShow $action): RedirectResponse
    {
        $this->authorize('markNoShow', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function reschedule(RescheduleBookingRequest $request, Booking $booking, RescheduleBooking $action): RedirectResponse
    {
        $this->authorize('reschedule', $booking);
        $action->handle($booking, $request->validated(), $request->user());

        return back();
    }

    private function slotsFor(AvailabilityService $availabilityService, Request $request): array
    {
        $employeeId = $request->query('employee_id');
        $serviceId = $request->query('service_id');
        $date = $request->query('date');

        if (! $employeeId || ! $serviceId || ! $date) {
            return [];
        }

        $employee = User::where('business_id', Business::current()->id)->find($employeeId);
        $service = Service::find($serviceId);

        if (! $employee || ! $service) {
            return [];
        }

        return $availabilityService->getAvailableSlots(
            Business::current(),
            $service,
            $employee,
            CarbonImmutable::parse($date, Business::current()->timezone),
        );
    }
}
