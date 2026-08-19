<?php

namespace App\Http\Controllers\Public;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RescheduleBookingRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyBookingsController extends Controller
{
    public function index(): Response
    {
        $bookings = Booking::withoutGlobalScope(BusinessScope::class)
            ->where('customer_id', request()->user()->id)
            ->with([
                'business:id,name,cancellation_hours',
                'employee:id,name',
                'service' => fn ($query) => $query->withoutGlobalScope(BusinessScope::class)->select('id', 'name'),
                'payments' => fn ($query) => $query->withoutGlobalScope(BusinessScope::class)->orderByDesc('id'),
            ])
            ->orderByDesc('starts_at')
            ->get();

        return Inertia::render('Public/MyBookings/Index', [
            'bookings' => $bookings->map(function (Booking $booking) {
                $payment = $booking->payments->first();

                return array_merge($booking->withoutRelations()->toArray(), [
                    'business' => $booking->business,
                    'employee' => $booking->employee,
                    'service' => $booking->service,
                    'payment' => $payment === null
                        ? null
                        : PaymentResource::make($payment->setRelation('booking', $booking))->resolve(),
                ]);
            }),
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

    public function rescheduleSlots(int $booking, AvailabilityService $availabilityService, Request $request): JsonResponse
    {
        $bookingModel = Booking::withoutGlobalScope(BusinessScope::class)->findOrFail($booking);
        $this->authorize('reschedule', $bookingModel);

        $business = $bookingModel->business;
        app()->instance(Business::class, $business);

        $date = $request->query('date');

        if (! $date || ! is_string($date)) {
            return response()->json(['slots' => []]);
        }

        try {
            $parsedDate = CarbonImmutable::parse($date, $business->timezone);
        } catch (\Exception) {
            return response()->json(['slots' => []]);
        }

        $slots = $availabilityService->getAvailableSlots(
            $business,
            $bookingModel->service,
            $bookingModel->employee,
            $parsedDate,
            excludeBookingId: $bookingModel->id,
        );

        return response()->json(['slots' => $slots]);
    }
}
