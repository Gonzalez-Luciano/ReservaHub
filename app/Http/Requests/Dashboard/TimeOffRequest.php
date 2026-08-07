<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class TimeOffRequest extends FormRequest
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
