<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Users\SetUserActiveStatus;
use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use PDO;
use Tests\TestCase;

/**
 * `DatabaseMigrations` en vez de `RefreshDatabase`: este test necesita que los
 * datos sembrados estén comiteados para que una segunda sesión de Postgres los
 * vea. `RefreshDatabase` envuelve el test en una transacción y lo impediría.
 */
class UserStatusConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function rawConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password']);
    }

    /**
     * Réplica exacta del guard de SetUserActiveStatus::assertAnotherOwnerRemains(),
     * ejecutada sobre una sesión de Postgres arbitraria.
     *
     * @return bool `true` si la desactivación se aplicó.
     */
    private function deactivateOwnerOn(PDO $session, int $businessId, int $targetId): bool
    {
        $session->beginTransaction();

        $statement = $session->prepare(
            'select id from users where business_id = :business and role = :role and is_active = true order by id for update'
        );
        $statement->execute(['business' => $businessId, 'role' => Role::Owner->value]);
        $activeOwnerIds = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (count($activeOwnerIds) <= 1 && in_array((string) $targetId, array_map('strval', $activeOwnerIds), true)) {
            $session->rollBack();

            return false;
        }

        $update = $session->prepare('update users set is_active = false where id = :id');
        $update->execute(['id' => $targetId]);

        $session->commit();

        return true;
    }

    public function test_two_concurrent_deactivations_cannot_leave_the_business_without_an_active_owner(): void
    {
        $business = Business::factory()->create();
        $firstOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $secondOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $sessionA = $this->rawConnection();
        $sessionB = $this->rawConnection();

        // A abre su transacción y toma los locks de los dos owners activos.
        $sessionA->beginTransaction();
        $lockStatement = $sessionA->prepare(
            'select id from users where business_id = :business and role = :role and is_active = true order by id for update'
        );
        $lockStatement->execute(['business' => $business->id, 'role' => Role::Owner->value]);
        $lockedIds = $lockStatement->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(2, $lockedIds);

        // A desactiva al primero y comitea, liberando los locks.
        $sessionA->prepare('update users set is_active = false where id = :id')
            ->execute(['id' => $firstOwner->id]);
        $sessionA->commit();

        // B corre ahora el mismo guard: ya no debe poder desactivar al que queda.
        $applied = $this->deactivateOwnerOn($sessionB, $business->id, $secondOwner->id);

        $this->assertFalse($applied, 'La segunda desactivación no debería haberse aplicado.');

        $activeOwners = User::query()
            ->where('business_id', $business->id)
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThanOrEqual(1, $activeOwners);
        $this->assertTrue($secondOwner->fresh()->is_active);
    }

    public function test_the_action_rejects_the_second_deactivation(): void
    {
        $business = Business::factory()->create();
        $firstOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $secondOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $action = app(SetUserActiveStatus::class);

        config()->set('session.driver', 'database');

        $action->handle($firstOwner, false);

        try {
            $action->handle($secondOwner, false);
            $this->fail('Se esperaba una ValidationException al desactivar al último owner activo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_active', $exception->errors());
        }

        $activeOwners = User::query()
            ->where('business_id', $business->id)
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThanOrEqual(1, $activeOwners);
    }
}
