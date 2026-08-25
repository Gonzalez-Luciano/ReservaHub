<?php

namespace Tests\Unit\Support;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_mode_is_off_by_default(): void
    {
        $this->assertFalse(config('demo.public_mode'));
    }

    public function test_the_seeder_uses_the_canonical_password(): void
    {
        config(['demo.password' => 'una-contrasena-distinta']);

        $this->seed(DemoSeeder::class);

        $owner = User::where('email', 'owner@reservahub.test')->firstOrFail();

        $this->assertTrue(Hash::check('una-contrasena-distinta', $owner->password));
    }

    public function test_the_account_list_matches_exactly_what_the_seeder_creates(): void
    {
        $this->seed(DemoSeeder::class);

        $seeded = User::query()->pluck('email')->sort()->values()->all();
        $declared = collect(config('demo.accounts'))->pluck('email')->sort()->values()->all();

        $this->assertSame(
            $seeded,
            $declared,
            'config/demo.php quedó desincronizada de DemoSeeder: demo:restore-access restauraría un conjunto equivocado.'
        );
    }

    public function test_every_declared_owner_points_at_a_real_demo_business(): void
    {
        $this->seed(DemoSeeder::class);

        foreach (config('demo.accounts') as $account) {
            if ($account['role'] !== 'owner') {
                continue;
            }

            $this->assertContains($account['business_slug'], config('demo.business_slugs'));

            $user = User::where('email', $account['email'])->firstOrFail();

            $this->assertSame($account['business_slug'], $user->business->slug);
        }
    }
}
