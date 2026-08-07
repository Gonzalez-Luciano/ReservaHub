<?php

namespace App\Http\Requests\Dashboard;

use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('day_of_week')) {
                return;
            }

            $employeeId = $this->route('employee')?->id ?? $this->route('schedule')?->employee_id;
            $scheduleId = $this->route('schedule')?->id;

            $exists = Schedule::where('employee_id', $employeeId)
                ->where('day_of_week', $this->input('day_of_week'))
                ->when($scheduleId, fn ($query) => $query->where('id', '!=', $scheduleId))
                ->exists();

            if ($exists) {
                $validator->errors()->add('day_of_week', 'Ya existe un horario para ese día.');
            }
        });
    }
}
