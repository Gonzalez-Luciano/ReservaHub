<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // No se usa la regla `current_password`: resuelve el guard por
            // defecto (`web`) y este mismo Request se reutiliza bajo
            // `auth:sanctum`, donde ese guard no tiene usuario.
            'current_password' => [
                'required', 'string',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! Hash::check((string) $value, $this->user()->password)) {
                        $fail('La contraseña actual no es correcta.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
