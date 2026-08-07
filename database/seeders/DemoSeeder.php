<?php

namespace Database\Seeders;

use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::create([
            'name' => 'Peluquería Demo',
            'slug' => 'peluqueria-demo',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'cancellation_hours' => 24,
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Owner Demo',
            'email' => 'owner@reservahub.test',
            'password' => 'password',
            'role' => Role::Owner,
            'business_id' => $business->id,
        ]);

        $employees = User::factory()
            ->count(2)
            ->sequence(
                ['name' => 'Ana Empleada', 'email' => 'ana@reservahub.test'],
                ['name' => 'Beto Empleado', 'email' => 'beto@reservahub.test'],
            )
            ->create([
                'password' => 'password',
                'role' => Role::Employee,
                'business_id' => $business->id,
            ]);

        $services = Service::factory()
            ->for($business)
            ->count(5)
            ->sequence(
                ['name' => 'Corte de cabello', 'duration_minutes' => 30, 'buffer_minutes' => 5, 'price' => 3500],
                ['name' => 'Coloración', 'duration_minutes' => 90, 'buffer_minutes' => 15, 'price' => 12000],
                ['name' => 'Manicura', 'duration_minutes' => 45, 'buffer_minutes' => 5, 'price' => 4000],
                ['name' => 'Masaje', 'duration_minutes' => 60, 'buffer_minutes' => 10, 'price' => 8000],
                ['name' => 'Depilación', 'duration_minutes' => 30, 'buffer_minutes' => 10, 'price' => 5000],
            )
            ->create();

        $weekdays = [DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday];

        foreach ($employees as $employee) {
            foreach ($weekdays as $day) {
                Schedule::factory()->for($business)->create([
                    'employee_id' => $employee->id,
                    'day_of_week' => $day,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                ]);
            }

            $employee->services()->sync($services->pluck('id'));
        }
    }
}
