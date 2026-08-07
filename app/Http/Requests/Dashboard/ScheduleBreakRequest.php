<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleBreakRequest extends FormRequest
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
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }
}
