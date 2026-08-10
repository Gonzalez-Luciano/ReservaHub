<?php

namespace App\Http\Controllers\Public;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RescheduleBookingRequest;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MyBookingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Public/MyBookings/Index', [
            'bookings' => Booking::withoutGlobalScope(BusinessScope::class)
                ->where('customer_id', request()->user()->id)
                ->with(['business:id,name,cancellation_hours', 'employee:id,name', 'service:id,name'])
                ->orderByDesc('starts_at')
                ->get(),
        ]);
    }

    public function cancel(int $booking, CancelBooking $action): RedirectResponse
    {
        $bookingModel = Booking::withoutGlobalScope(BusinessScope::class)->findOrFail($booking);
        $this->authorize('cancel', $bookingModel);

        $action->handle($bookingModel, request()->user());

        return redirect()->route('public.bookings.mine.index');
    }

    public function reschedule(RescheduleBookingRequest $request, int $booking, RescheduleBooking $action): RedirectResponse
    {
        $bookingModel = Booking::withoutGlobalScope(BusinessScope::class)->findOrFail($booking);
        $this->authorize('reschedule', $bookingModel);

        $action->handle($bookingModel, $request->validated(), request()->user());

        return redirect()->route('public.bookings.mine.index');
    }
}
