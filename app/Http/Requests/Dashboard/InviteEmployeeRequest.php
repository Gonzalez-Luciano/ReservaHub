<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use App\Models\EmployeeInvitation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && in_array($this->user()->role, Role::managers(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email'),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (EmployeeInvitation::where('email', $value)->pending()->exists()) {
                        $fail('Ya existe una invitación pendiente para este email.');
                    }
                },
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
