<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AvailabilityRequest;
use App\Http\Resources\SlotResource;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function index(
        AvailabilityRequest $request,
        AvailabilityService $availability,
    ): JsonResponse {
        $business = Business::current();

        $service = Service::findOrFail($request->validated('service_id'));

        $employee = User::where('business_id', $business->id)
            ->where('role', Role::Employee)
            ->findOrFail($request->validated('employee_id'));

        $date = CarbonImmutable::parse($request->validated('date'), $business->timezone);

        $slots = $availability->getAvailableSlots($business, $service, $employee, $date);

        return ApiResponse::success(SlotResource::collection($slots));
    }
}
