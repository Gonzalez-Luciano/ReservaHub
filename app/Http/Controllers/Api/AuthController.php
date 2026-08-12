<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        $invalidCredentials = ! $user
            || ! Hash::check($request->validated('password'), $user->password)
            || ! $user->is_active
            || ($user->hasBusiness() && ! $user->business->is_active);

        if ($invalidCredentials) {
            return ApiResponse::error('Estas credenciales no coinciden con nuestros registros.', null, 401);
        }

        $token = $user->createToken($request->validated('device_name'))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Sesión iniciada correctamente.');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Sanctum::actingAs() en tests entrega un TransientToken sin delete().
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(null, 'Sesión cerrada correctamente.');
    }
}
