<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
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
            'starts_at' => ['required', 'date'],
        ];
    }
}
