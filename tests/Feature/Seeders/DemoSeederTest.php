<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_expected_records(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseHas('users', ['email' => 'owner@reservahub.test', 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'ana@reservahub.test', 'role' => 'employee']);
        $this->assertDatabaseHas('users', ['email' => 'beto@reservahub.test', 'role' => 'employee']);
        $this->assertDatabaseCount('services', 5);
        $this->assertDatabaseCount('schedules', 10);
        $this->assertDatabaseCount('employee_service', 10);
    }
}
