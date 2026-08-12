<?php

namespace App\Http\Controllers\Api;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\ResolvesBookingScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookingIndexRequest;
use App\Http\Requests\Api\RescheduleBookingRequest;
use App\Http\Requests\Api\StoreBookingRequest;
use App\Http\Requests\Api\StoreCustomerBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ResolvesBookingScope;

    public function index(BookingIndexRequest $request): JsonResponse
    {
        $query = $this->bookingQueryFor($request->user())->with($this->bookingRelations());

        $timezone = Business::current()?->timezone ?? config('app.timezone');

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->validated('from')) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }

        if ($to = $request->validated('to')) {
            $query->where('starts_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc());
        }

        if ($employeeId = $request->validated('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        $bookings = $query->orderByDesc('starts_at')->paginate($request->perPage());

        return ApiResponse::paginated(BookingResource::collection($bookings));
    }

    public function show(Request $request, int $booking): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('view', $model);

        return ApiResponse::success(BookingResource::make($model->load($this->bookingRelations())));
    }

    public function store(StoreBookingRequest $request, CreateBooking $action): JsonResponse
    {
        $business = Business::current();

        $this->authorize('createByStaff', [Booking::class, $business]);

        $customer = User::where('role', Role::Customer)
            ->where('email', $request->validated('customer_email'))
            ->firstOrFail();

        $booking = $action->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'api',
            'notes' => $request->validated('notes'),
        ], $request->user());

        return ApiResponse::success(
            BookingResource::make($booking->load($this->bookingRelations())),
            'Reserva creada correctamente.',
            201,
        );
    }

    public function storeForCustomer(StoreCustomerBookingRequest $request, Business $business, CreateBooking $action): JsonResponse
    {
        $this->authorize('createByCustomer', Booking::class);

        $booking = $action->handle($business, [
            'customer_id' => $request->user()->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'api',
            'notes' => null,
        ], $request->user());

        return ApiResponse::success(
            BookingResource::make($booking->load($this->bookingRelations())),
            'Reserva creada correctamente.',
            201,
        );
    }

    public function update(RescheduleBookingRequest $request, int $booking, RescheduleBooking $action): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('reschedule', $model);

        $updated = $action->handle($model, ['starts_at' => $request->validated('starts_at')], $request->user());

        return ApiResponse::success(
            BookingResource::make($updated->load($this->bookingRelations())),
            'Reserva reprogramada correctamente.',
        );
    }

    public function cancel(Request $request, int $booking, CancelBooking $action): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('cancel', $model);

        $cancelled = $action->handle($model, $request->user());

        return ApiResponse::success(
            BookingResource::make($cancelled->load($this->bookingRelations())),
            'Reserva cancelada correctamente.',
        );
    }

    public function confirm(Request $request, int $booking, ConfirmBooking $action): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('confirm', $model);

        $confirmed = $action->handle($model, $request->user());

        return ApiResponse::success(
            BookingResource::make($confirmed->load($this->bookingRelations())),
            'Reserva confirmada correctamente.',
        );
    }
}
