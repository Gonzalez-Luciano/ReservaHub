<?php

namespace Tests\Unit\Database;

use PDO;
use Tests\TestCase;

class AdvisoryLockTest extends TestCase
{
    private function rawConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password']);
    }

    public function test_a_second_session_cannot_acquire_the_lock_while_the_first_holds_it_and_can_once_it_is_released(): void
    {
        $sessionA = $this->rawConnection();
        $sessionB = $this->rawConnection();

        $sessionA->beginTransaction();
        $sessionA->exec("select pg_advisory_xact_lock(hashtext('booking-employee-42'))");

        $sessionB->beginTransaction();
        $acquiredWhileHeld = $sessionB->query("select pg_try_advisory_xact_lock(hashtext('booking-employee-42')) as acquired")->fetchColumn();
        $sessionB->commit();

        // pdo_pgsql returns native PHP bool for boolean columns on PHP 8.4+
        // (older versions returned the raw 't'/'f' wire strings); accept either
        // representation so this assertion targets the lock semantics, not the
        // PDO driver's type-conversion behavior for a given PHP version.
        $this->assertTrue(in_array($acquiredWhileHeld, [false, 'f'], true));

        $sessionA->commit();

        $sessionB->beginTransaction();
        $acquiredAfterRelease = $sessionB->query("select pg_try_advisory_xact_lock(hashtext('booking-employee-42')) as acquired")->fetchColumn();
        $sessionB->commit();

        $this->assertTrue(in_array($acquiredAfterRelease, [true, 't'], true));
    }
}
