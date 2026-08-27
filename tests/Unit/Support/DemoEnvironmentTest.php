<?php

namespace Tests\Unit\Support;

use App\Support\DemoEnvironment;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private function guard(): DemoEnvironment
    {
        return $this->app->make(DemoEnvironment::class);
    }

    private function enableDemoMode(): void
    {
        config([
            'demo.public_mode' => true,
            'demo.target_database' => DB::connection()->getDatabaseName(),
        ]);
    }

    public function test_it_fails_when_demo_mode_is_off(): void
    {
        config(['demo.public_mode' => false]);

        $this->assertStringContainsString('DEMO_PUBLIC_MODE', (string) $this->guard()->guardFailure());
    }

    public function test_it_fails_when_the_target_database_is_not_configured(): void
    {
        config(['demo.public_mode' => true, 'demo.target_database' => null]);

        $this->assertStringContainsString('DEMO_TARGET_DATABASE', (string) $this->guard()->guardFailure());
    }

    public function test_it_fails_when_the_target_database_does_not_match_the_connected_one(): void
    {
        config(['demo.public_mode' => true, 'demo.target_database' => 'otra_base']);

        $failure = (string) $this->guard()->guardFailure();

        $this->assertStringContainsString('otra_base', $failure);
        $this->assertStringContainsString(DB::connection()->getDatabaseName(), $failure);
    }

    public function test_it_fails_when_the_database_holds_no_demo_business(): void
    {
        $this->enableDemoMode();

        $this->assertStringContainsString('peluqueria-demo', (string) $this->guard()->guardFailure());
    }

    public function test_it_passes_once_the_demo_dataset_is_present(): void
    {
        $this->enableDemoMode();
        $this->seed(DemoSeeder::class);

        $this->assertNull($this->guard()->guardFailure());
    }

    public function test_a_business_created_by_a_visitor_does_not_break_the_guard(): void
    {
        $this->enableDemoMode();
        $this->seed(DemoSeeder::class);

        DB::table('businesses')->insert([
            'name' => 'Negocio de un visitante',
            'slug' => 'negocio-de-un-visitante',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'cancellation_hours' => 24,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(
            $this->guard()->guardFailure(),
            'La guarda es de presencia, no de exclusividad: los visitantes crean negocios durante la semana.'
        );
    }
}
