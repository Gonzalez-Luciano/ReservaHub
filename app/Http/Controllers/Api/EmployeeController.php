<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Business;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = Business::current();

        $query = User::where('business_id', $business->id)
            ->where('role', Role::Employee)
            ->where('is_active', true)
            ->orderBy('name');

        $serviceId = $request->query('service_id');

        if ($serviceId !== null && is_numeric($serviceId)) {
            $query->whereHas('services', fn ($services) => $services->where('services.id', (int) $serviceId));
        }

        return ApiResponse::success(EmployeeResource::collection($query->get()));
    }
}
