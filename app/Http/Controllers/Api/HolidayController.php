<?php

namespace App\Http\Controllers\Api;

use App\Actions\Holidays\CreateBusinessHoliday;
use App\Actions\Holidays\DeleteBusinessHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HolidayController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', BusinessHoliday::class);

        $holidays = BusinessHoliday::orderBy('starts_on')->get();

        return ApiResponse::success(HolidayResource::collection($holidays));
    }

    public function store(StoreHolidayRequest $request, CreateBusinessHoliday $action): JsonResponse
    {
        $this->authorize('create', BusinessHoliday::class);

        $holiday = $action->handle(Business::current(), $request->validated());

        return ApiResponse::success(new HolidayResource($holiday), 'Feriado creado.', 201);
    }

    public function destroy(BusinessHoliday $holiday, DeleteBusinessHoliday $action): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $action->handle($holiday);

        return ApiResponse::success(null, 'Feriado eliminado.');
    }
}
