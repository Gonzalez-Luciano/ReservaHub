<?php

namespace Tests\Feature\Seeders;

use App\Models\Business;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_expected_records(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertDatabaseCount('businesses', 2);
        $this->assertDatabaseHas('users', ['email' => 'owner@reservahub.test', 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'ana@reservahub.test', 'role' => 'employee']);
        $this->assertDatabaseHas('users', ['email' => 'beto@reservahub.test', 'role' => 'employee']);
        $this->assertDatabaseHas('users', ['email' => 'owner2@reservahub.test', 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'carla@reservahub.test', 'role' => 'employee']);
        $this->assertDatabaseCount('services', 7);
        $this->assertDatabaseCount('schedules', 15);
        $this->assertDatabaseCount('employee_service', 12);
    }

    public function test_it_seeds_two_demo_businesses(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(
            ['estudio-demo', 'peluqueria-demo'],
            Business::orderBy('slug')->pluck('slug')->all(),
        );
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(2, Business::count());
        $this->assertSame(1, Business::where('slug', 'estudio-demo')->count());
    }

    public function test_it_seeds_the_second_business_when_the_first_already_exists(): void
    {
        Business::create([
            'name' => 'Peluquería Demo',
            'slug' => 'peluqueria-demo',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'cancellation_hours' => 24,
            'is_active' => true,
        ]);

        $this->seed(DemoSeeder::class);

        $this->assertTrue(Business::where('slug', 'estudio-demo')->exists());
    }
}
