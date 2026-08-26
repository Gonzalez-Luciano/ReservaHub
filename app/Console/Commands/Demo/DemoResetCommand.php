<?php

namespace App\Console\Commands\Demo;

use App\Support\DemoEnvironment;
use App\Support\DemoResetLock;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reset semanal del dataset de demo (lunes 00:00 America/Argentina/Buenos_Aires).
 *
 * Comando DESTRUCTIVO: borra la base entera. Tres guardas de DemoEnvironment
 * más --force en ejecución no interactiva. Si cualquiera falla, ABORT sin
 * tocar un solo dato.
 *
 * Mailpit no forma parte de esto: es otro servicio y su limpieza diaria la
 * ejecuta operaciones.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset {--force : Confirmar la destrucción en ejecución no interactiva}';

    protected $description = 'Reinicia el dataset de la demo pública al estado canónico. Destructivo.';

    public function handle(DemoEnvironment $environment, DemoResetLock $lock): int
    {
        if ($failure = $environment->guardFailure()) {
            $this->error('ABORT: '.$failure);

            return self::FAILURE;
        }

        if (! $this->confirmed()) {
            $this->error('ABORT: hace falta --force para borrar la base en ejecución no interactiva.');

            return self::FAILURE;
        }

        if (! $lock->acquire()) {
            $this->error('ABORT: ya hay un demo:reset en curso.');

            return self::FAILURE;
        }

        try {
            return $this->reset();
        } catch (Throwable $e) {
            // Nunca reportar éxito ante un fallo: el operador lee el exit code.
            $this->error('ABORT: el reset falló y quedó a medias: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function confirmed(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->runningAtARealTerminal()) {
            return false;
        }

        return $this->confirm('Esto BORRA toda la base de la demo. ¿Continuar?', false);
    }

    /**
     * `$this->input->isInteractive()` por sí solo no basta: Symfony la deja en
     * `true` por defecto salvo que alguien pase `--no-interaction`
     * explícitamente, así que un cron sin tty igual la reportaría `true` y
     * dispararía el prompt de confirmación (que en un flujo desatendido lee
     * EOF de un stdin vacío y no cuelga, pero tampoco es la señal que
     * queremos: la comprobación explícita evita depender de ese detalle). Un
     * tty real es la señal correcta de que hay un humano del otro lado
     * capaz de contestar la pregunta.
     */
    private function runningAtARealTerminal(): bool
    {
        return $this->input->isInteractive()
            && defined('STDIN')
            && stream_isatty(STDIN);
    }

    private function reset(): int
    {
        // Antes de borrar: si el worker sigue consumiendo mientras se dropean
        // las tablas, procesa jobs contra un dataset que ya no existe.
        $this->clearQueue();

        $this->callSilently('migrate:fresh', ['--force' => true]);

        // Exclusivamente DemoSeeder. DatabaseSeeder crea test@example.com.
        $this->callSilently('db:seed', [
            '--class' => 'Database\Seeders\DemoSeeder',
            '--force' => true,
        ]);

        // Después de sembrar: descarta lo que se haya encolado durante la
        // ventana y que todavía referencie los IDs viejos.
        $this->clearQueue();

        $this->callSilently('cache:clear');

        $this->info('Dataset de la demo reiniciado.');

        return self::SUCCESS;
    }

    /**
     * `migrate:fresh` solo vacía la tabla `jobs`, que este proyecto no usa:
     * la cola real es Redis (QUEUE_CONNECTION=redis).
     */
    private function clearQueue(): void
    {
        $this->callSilently('queue:clear', [
            'connection' => config('queue.default'),
            '--force' => true,
        ]);
    }
}
