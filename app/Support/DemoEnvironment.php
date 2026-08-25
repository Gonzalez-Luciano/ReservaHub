<?php

namespace App\Support;

use Illuminate\Database\DatabaseManager;

/**
 * Guardas compartidas por `demo:reset` y `demo:restore-access`.
 *
 * Tres comprobaciones independientes a propósito: una bandera que declara la
 * intención, un identificador no booleano que ata el comando a una base
 * concreta, y una inspección del estado real de esa base. APP_ENV no
 * participa: una base productiva de verdad también tiene APP_ENV=production.
 */
class DemoEnvironment
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * @return string|null Motivo del rechazo, o null si se puede operar.
     */
    public function guardFailure(): ?string
    {
        if (config('demo.public_mode') !== true) {
            return 'DEMO_PUBLIC_MODE no es true. Este comando solo corre en el deployment de demo pública.';
        }

        $expected = config('demo.target_database');

        if (blank($expected)) {
            return 'DEMO_TARGET_DATABASE está vacía. Es la segunda confirmación y es obligatoria.';
        }

        $actual = $this->db->connection()->getDatabaseName();

        if ($expected !== $actual) {
            return sprintf(
                'DEMO_TARGET_DATABASE dice "%s" pero la conexión apunta a "%s".',
                $expected,
                $actual,
            );
        }

        return $this->unrecognisedDataset();
    }

    /**
     * Una base sin tabla `businesses` es un primer arranque legítimo. Una que
     * la tiene pero no contiene ningún slug canónico no es la base de la demo
     * y no se toca. Presencia, nunca exclusividad.
     */
    private function unrecognisedDataset(): ?string
    {
        $connection = $this->db->connection();

        if (! $connection->getSchemaBuilder()->hasTable('businesses')) {
            return null;
        }

        $slugs = config('demo.business_slugs');

        if ($connection->table('businesses')->whereIn('slug', $slugs)->exists()) {
            return null;
        }

        return sprintf(
            'La base "%s" no contiene ninguno de los negocios de demo (%s). No parece la base de la demo.',
            $connection->getDatabaseName(),
            implode(', ', $slugs),
        );
    }
}
