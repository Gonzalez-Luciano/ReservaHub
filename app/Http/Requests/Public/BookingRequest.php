<?php

namespace App\Http\Requests\Public;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === Role::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
        ];
    }
}
