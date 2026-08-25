<?php

namespace Tests\Feature\Console;

use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoRestoreAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UserAccessRevoker falla cerrado con cualquier driver que no sea
        // `database`, y phpunit.xml fija `array` para el resto de la suite.
        config(['session.driver' => 'database']);

        config([
            'demo.public_mode' => true,
            'demo.target_database' => DB::connection()->getDatabaseName(),
        ]);

        $this->seed(DemoSeeder::class);
    }

    public function test_it_aborts_without_demo_mode(): void
    {
        config(['demo.public_mode' => false]);

        $this->artisan('demo:restore-access')
            ->expectsOutputToContain('ABORT')
            ->assertExitCode(1);
    }

    public function test_it_restores_a_password_changed_by_a_visitor(): void
    {
        $owner = User::where('email', 'owner@reservahub.test')->firstOrFail();
        $owner->forceFill(['password' => Hash::make('secuestrada')])->save();

        $this->artisan('demo:restore-access')->assertExitCode(0);

        $this->assertTrue(Hash::check(config('demo.password'), $owner->fresh()->password));
    }

    public function test_it_restores_the_canonical_email_of_an_owner_renamed_by_a_visitor(): void
    {
        $owner = User::where('email', 'owner@reservahub.test')->firstOrFail();
        $owner->forceFill(['email' => 'secuestrada@example.com'])->save();

        $this->artisan('demo:restore-access')->assertExitCode(0);

        $this->assertSame('owner@reservahub.test', $owner->fresh()->email);
    }

    public function test_it_reactivates_a_deactivated_demo_account(): void
    {
        $employee = User::where('email', 'ana@reservahub.test')->firstOrFail();
        $employee->forceFill(['is_active' => false])->save();

        $this->artisan('demo:restore-access')->assertExitCode(0);

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_it_revokes_sessions_tokens_and_reset_links(): void
    {
        $owner = User::where('email', 'owner@reservahub.test')->firstOrFail();
        $owner->createToken('secuestrado');

        DB::table('sessions')->insert([
            'id' => 'sesion-de-un-visitante',
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'owner@reservahub.test',
            'token' => 'token-de-un-visitante',
            'created_at' => now(),
        ]);

        $this->artisan('demo:restore-access')->assertExitCode(0);

        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $owner->id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'owner@reservahub.test')->count());
    }

    public function test_it_does_not_touch_the_functional_dataset(): void
    {
        $before = Booking::withoutGlobalScope(BusinessScope::class)->count();

        $this->artisan('demo:restore-access')->assertExitCode(0);

        $this->assertSame($before, Booking::withoutGlobalScope(BusinessScope::class)->count());
    }

    public function test_it_leaves_accounts_outside_the_demo_list_alone(): void
    {
        $visitor = User::factory()->customer()->create([
            'email' => 'visitante@example.com',
            'password' => Hash::make('la-mia'),
        ]);
        $visitor->createToken('la-mia');

        $this->artisan('demo:restore-access')->assertExitCode(0);

        $this->assertTrue(Hash::check('la-mia', $visitor->fresh()->password));
        $this->assertSame(1, $visitor->tokens()->count());
    }
}
