<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\ChangePassword;
use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\AccountResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new AccountResource($request->user()));
    }

    public function updateProfile(UpdateProfileRequest $request, UpdateProfile $action): JsonResponse
    {
        $user = $action->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('email'),
        );

        return ApiResponse::success(new AccountResource($user), 'Perfil actualizado.');
    }

    public function updatePassword(UpdatePasswordRequest $request, ChangePassword $action): JsonResponse
    {
        // `null`: por API cae todo, incluido el token que hizo esta llamada.
        $action->handle($request->user(), $request->validated('password'), null);

        return ApiResponse::success(
            null,
            'Contraseña actualizada. Todos los tokens fueron revocados; iniciá sesión de nuevo.',
        );
    }
}
