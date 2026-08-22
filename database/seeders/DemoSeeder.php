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
        $this->seedBusiness(
            slug: 'peluqueria-demo',
            name: 'Peluquería Demo',
            ownerEmail: 'owner@reservahub.test',
            employees: [
                ['name' => 'Ana Empleada', 'email' => 'ana@reservahub.test'],
                ['name' => 'Beto Empleado', 'email' => 'beto@reservahub.test'],
            ],
            services: [
                ['name' => 'Corte de cabello', 'duration_minutes' => 30, 'buffer_minutes' => 5, 'price' => 3500],
                ['name' => 'Coloración', 'duration_minutes' => 90, 'buffer_minutes' => 15, 'price' => 12000],
                ['name' => 'Manicura', 'duration_minutes' => 45, 'buffer_minutes' => 5, 'price' => 4000],
                ['name' => 'Masaje', 'duration_minutes' => 60, 'buffer_minutes' => 10, 'price' => 8000],
                ['name' => 'Depilación', 'duration_minutes' => 30, 'buffer_minutes' => 10, 'price' => 5000],
            ],
        );

        $this->seedBusiness(
            slug: 'estudio-demo',
            name: 'Estudio Demo',
            ownerEmail: 'owner2@reservahub.test',
            employees: [
                ['name' => 'Carla Empleada', 'email' => 'carla@reservahub.test'],
            ],
            services: [
                ['name' => 'Clase de guitarra', 'duration_minutes' => 60, 'buffer_minutes' => 10, 'price' => 6000],
                ['name' => 'Grabación de demo', 'duration_minutes' => 120, 'buffer_minutes' => 30, 'price' => 20000],
            ],
        );
    }

    /**
     * @param  array<int, array{name: string, email: string}>  $employees
     * @param  array<int, array{name: string, duration_minutes: int, buffer_minutes: int, price: int}>  $services
     */
    private function seedBusiness(string $slug, string $name, string $ownerEmail, array $employees, array $services): void
    {
        // Idempotencia por negocio: un guard global impediría sembrar un negocio
        // nuevo en una instalación donde el primero ya existe.
        if (Business::where('slug', $slug)->exists()) {
            return;
        }

        $business = Business::create([
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'cancellation_hours' => 24,
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => $name.' Owner',
            'email' => $ownerEmail,
            'password' => 'password',
            'role' => Role::Owner,
            'business_id' => $business->id,
        ]);

        $employeeModels = User::factory()
            ->count(count($employees))
            ->sequence(...$employees)
            ->create([
                'password' => 'password',
                'role' => Role::Employee,
                'business_id' => $business->id,
            ]);

        $serviceModels = Service::factory()
            ->for($business)
            ->count(count($services))
            ->sequence(...$services)
            ->create();

        $weekdays = [DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday];

        foreach ($employeeModels as $employee) {
            foreach ($weekdays as $day) {
                Schedule::factory()->for($business)->create([
                    'employee_id' => $employee->id,
                    'day_of_week' => $day,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                ]);
            }

            $employee->services()->sync($serviceModels->pluck('id'));
        }
    }
}
