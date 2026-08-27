<?php

namespace Tests\Feature\Console;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoResetCommandTest extends TestCase
{
    /**
     * Ni `RefreshDatabase` ni `DatabaseMigrations`: el comando bajo prueba
     * corre su propio `migrate:fresh` real, y ambos traits asumen que son
     * dueños exclusivos del ciclo migrar/revertir de la clase de test. Cuando
     * el SUT también llama a `migrate:fresh` a mitad de un test, pisa esa
     * contabilidad interna — el síntoma es que el primer test (o los dos
     * primeros) pasan y los siguientes fallan con `relation "migrations" does
     * not exist` / `relation "businesses" does not exist`, porque el
     * `tearDown` del trait ya no sabe en qué estado quedó el esquema.
     *
     * La solución es no delegarle el ciclo de vida a ningún trait: cada test
     * hace su propio `migrate:fresh` a mano en `setUp()`, exactamente la
     * misma operación idempotente que ejecuta el propio comando. No hace
     * falta un `tearDown()`: el `setUp()` del siguiente test ya vuelve a
     * dejar el esquema en cero.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);

        config([
            'demo.public_mode' => true,
            'demo.target_database' => DB::connection()->getDatabaseName(),
        ]);

        $this->seed(DemoSeeder::class);
    }

    public function test_it_aborts_without_demo_mode(): void
    {
        config(['demo.public_mode' => false]);

        $this->artisan('demo:reset --force')
            ->expectsOutputToContain('ABORT')
            ->assertExitCode(1);

        $this->assertSame(2, Business::withoutGlobalScopes()->count());
    }

    public function test_it_aborts_when_pointed_at_the_wrong_database(): void
    {
        config(['demo.target_database' => 'una_base_que_no_es']);

        $this->artisan('demo:reset --force')
            ->expectsOutputToContain('ABORT')
            ->assertExitCode(1);

        $this->assertSame(2, Business::withoutGlobalScopes()->count());
    }

    public function test_it_refuses_to_run_non_interactively_without_force(): void
    {
        $this->artisan('demo:reset')
            ->expectsOutputToContain('--force')
            ->assertExitCode(1);

        $this->assertSame(2, Business::withoutGlobalScopes()->count());
    }

    public function test_it_destroys_everything_a_visitor_created(): void
    {
        $visitor = User::factory()->customer()->create(['email' => 'visitante@example.com']);

        $this->artisan('demo:reset --force')->assertExitCode(0);

        $this->assertNull(User::withoutGlobalScopes()->find($visitor->id));
        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'visitante@example.com')->count());
    }

    public function test_it_reseeds_exactly_the_canonical_dataset(): void
    {
        $this->artisan('demo:reset --force')->assertExitCode(0);

        $this->assertSame(2, Business::withoutGlobalScopes()->count());
        $this->assertSame(23, Booking::withoutGlobalScope(BusinessScope::class)->count());
        $this->assertSame(11, User::withoutGlobalScopes()->count());
    }

    public function test_it_never_runs_the_database_seeder(): void
    {
        $this->artisan('demo:reset --force')->assertExitCode(0);

        $this->assertSame(
            0,
            User::withoutGlobalScopes()->where('email', 'test@example.com')->count(),
            'DatabaseSeeder crea test@example.com: demo:reset jamás debe invocarlo.'
        );
    }

    public function test_it_returns_the_demo_accounts_to_their_published_state(): void
    {
        $this->artisan('demo:reset --force')->assertExitCode(0);

        $owner = User::withoutGlobalScopes()->where('email', 'owner@reservahub.test')->firstOrFail();

        $this->assertTrue(Hash::check(config('demo.password'), $owner->password));
        $this->assertTrue($owner->is_active);
    }

    // No hay un test acá para "demo:reset limpia la cola real de Redis".
    // phpunit.xml fuerza QUEUE_CONNECTION=sync, y Laravel's SyncQueue no
    // implementa ClearableQueue (verificado leyendo
    // vendor/laravel/framework/src/Illuminate/Queue/SyncQueue.php): bajo
    // sync, `queue:clear` nunca toca una cola real, así que ningún test aquí
    // puede probar honestamente que se vacía Redis sin Redis de verdad.
    // Un primer intento con `Queue::fake()` + `assertSame(0,
    // DB::table('jobs')->count())` habría pasado exactamente igual con
    // `clearQueue()` borrado del código: fake() intercepta la cola entera
    // (nada llega a Redis NI a la tabla `jobs` sea cual sea el resultado), y
    // la tabla `jobs` ni siquiera es el store que usa este proyecto
    // (QUEUE_CONNECTION=redis) — doblemente la aserción equivocada. La
    // verificación real, con Redis de verdad, es la Tarea 12 (Paso 14),
    // contra el stack productivo.

    public function test_two_runs_cannot_overlap(): void
    {
        // Segunda conexión real: el advisory lock solo entra en contención
        // entre sesiones distintas de PostgreSQL.
        config(['database.connections.demo_lock_probe' => config('database.connections.pgsql')]);

        $held = DB::connection('demo_lock_probe')
            ->selectOne("select pg_try_advisory_lock(hashtext('reservahub-demo-reset')) as locked");

        $this->assertTrue((bool) $held->locked, 'La sonda tiene que poder tomar el lock primero.');

        try {
            $this->artisan('demo:reset --force')
                ->expectsOutputToContain('ABORT')
                ->assertExitCode(1);

            $this->assertSame(2, Business::withoutGlobalScopes()->count());
        } finally {
            DB::connection('demo_lock_probe')
                ->statement("select pg_advisory_unlock(hashtext('reservahub-demo-reset'))");
            DB::purge('demo_lock_probe');
        }
    }

    public function test_a_guard_failure_is_never_reported_as_success(): void
    {
        config(['demo.public_mode' => false]);

        $exitCode = $this->artisan('demo:reset --force')->run();

        $this->assertNotSame(0, $exitCode);
    }
}
