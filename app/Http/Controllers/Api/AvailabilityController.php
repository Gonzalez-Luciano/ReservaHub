<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(
        private AvailabilityService $availabilityService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|numeric',
            'employee_id' => 'required|numeric',
            'date' => 'required|date_format:Y-m-d',
        ]);

        $business = Business::current();

        // Load service scoped to business
        $service = Service::where('business_id', $business->id)
            ->findOrFail($validated['service_id']);

        // Load employee scoped to business
        $employee = User::where('business_id', $business->id)
            ->findOrFail($validated['employee_id']);

        // Convert date string to CarbonImmutable (business timezone)
        $date = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $validated['date'],
            $business->timezone
        )->startOfDay();

        // Get available slots
        $slots = $this->availabilityService->getAvailableSlots(
            $business,
            $service,
            $employee,
            $date
        );

        return ApiResponse::success($slots);
    }
}
