<?php

namespace App\Http\Controllers\Api;

use App\Actions\Users\SetUserActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserStatusController extends Controller
{
    public function update(UpdateUserStatusRequest $request, User $user, SetUserActiveStatus $action): JsonResponse
    {
        $this->authorize('setActiveStatus', $user);

        $result = $action->handle($user, $request->boolean('is_active'));

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'future_bookings_count' => $result['future_bookings_count'],
        ], $result['user']->is_active ? 'Usuario activado.' : 'Usuario desactivado.');
    }
}
