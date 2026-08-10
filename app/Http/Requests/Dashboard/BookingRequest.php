<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && in_array($this->user()->role, Role::businessStaff(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_email' => [
                'required',
                'email',
                Rule::exists('users', 'email')->where(fn ($query) => $query->where('role', Role::Customer->value)),
            ],
            'employee_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_email.exists' => 'No existe un cliente registrado con ese email.',
        ];
    }
}
