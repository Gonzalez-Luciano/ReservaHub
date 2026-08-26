<?php

namespace App\Support;

use Illuminate\Database\DatabaseManager;

/**
 * Lock de exclusión mutua para `demo:reset`.
 *
 * Advisory lock de SESIÓN de PostgreSQL, no de transacción y no de la caché.
 * Dos razones concretas:
 *
 * - `Cache::lock()` viviría en la tabla `cache_locks` (CACHE_STORE=database),
 *   que `migrate:fresh` dropea a mitad del propio reset.
 * - `pg_advisory_xact_lock` (el que usa CreateBooking) se soltaría al cerrar
 *   la primera transacción, y este lock tiene que cubrir varias.
 */
class DemoResetLock
{
    private const KEY = 'reservahub-demo-reset';

    public function __construct(private DatabaseManager $db) {}

    public function acquire(): bool
    {
        $result = $this->db->connection()
            ->selectOne('select pg_try_advisory_lock(hashtext(?)) as locked', [self::KEY]);

        return (bool) $result->locked;
    }

    public function release(): void
    {
        $this->db->connection()
            ->statement('select pg_advisory_unlock(hashtext(?))', [self::KEY]);
    }
}
