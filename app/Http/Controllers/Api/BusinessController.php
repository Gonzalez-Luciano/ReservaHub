<?php

namespace App\Http\Controllers\Api;

use App\Actions\Businesses\UpdateBusinessSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BusinessController extends Controller
{
    public function index(): JsonResponse
    {
        $businesses = Business::where('is_active', true)->orderBy('name')->get();

        return ApiResponse::success(BusinessResource::collection($businesses));
    }

    public function show(): JsonResponse
    {
        $business = Business::current();

        $this->authorize('view', $business);

        return ApiResponse::success(new BusinessResource($business));
    }

    public function update(UpdateBusinessRequest $request, UpdateBusinessSettings $action): JsonResponse
    {
        $business = Business::current();

        $this->authorize('update', $business);

        $action->handle($business, $request->validated());

        return ApiResponse::success(new BusinessResource($business), 'Ajustes actualizados.');
    }
}
