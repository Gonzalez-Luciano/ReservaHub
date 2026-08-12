<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesBookingScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookingIndexRequest;
use App\Http\Resources\BookingResource;
use App\Models\Business;
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
}
