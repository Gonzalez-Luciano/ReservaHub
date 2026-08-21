<?php

namespace Tests\Feature\Realtime;

use Illuminate\Support\Env;
use Tests\TestCase;

class ReverbConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function reverbConfigWith(?string $allowedOrigins): array
    {
        $repository = Env::getRepository();

        $allowedOrigins === null
            ? $repository->clear('REVERB_ALLOWED_ORIGINS')
            : $repository->set('REVERB_ALLOWED_ORIGINS', $allowedOrigins);

        try {
            return require config_path('reverb.php');
        } finally {
            $repository->clear('REVERB_ALLOWED_ORIGINS');
        }
    }

    public function test_allowed_origins_are_split_and_trimmed(): void
    {
        $config = $this->reverbConfigWith(' localhost , reservas.example.test ');

        $this->assertSame(
            ['localhost', 'reservas.example.test'],
            $config['apps']['apps'][0]['allowed_origins']
        );
    }

    public function test_empty_entries_are_discarded(): void
    {
        $config = $this->reverbConfigWith('localhost,,  ,');

        $this->assertSame(['localhost'], $config['apps']['apps'][0]['allowed_origins']);
    }

    public function test_a_missing_value_fails_closed_to_localhost_only(): void
    {
        // Sin la variable, se acepta solo el origen de desarrollo. Nunca '*':
        // una configuración ausente en producción tiene que negar, no abrir.
        $config = $this->reverbConfigWith(null);

        $this->assertSame(['localhost'], $config['apps']['apps'][0]['allowed_origins']);
    }

    public function test_the_reverb_broadcast_connection_exists(): void
    {
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
    }
}
