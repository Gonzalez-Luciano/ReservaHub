<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeServicesRequest extends FormRequest
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
            'service_ids' => ['array'],
            'service_ids.*' => ['integer'],
        ];
    }
}
