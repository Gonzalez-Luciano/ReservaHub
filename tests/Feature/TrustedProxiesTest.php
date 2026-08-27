<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__trusted-proxy-probe', fn () => response()->json([
            'secure' => request()->isSecure(),
            'url' => request()->url(),
        ]));
    }

    public function test_forwarded_proto_is_ignored_without_trusted_proxies(): void
    {
        config(['app.url' => 'http://localhost']);

        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/__trusted-proxy-probe');

        $response->assertJson(['secure' => false]);
    }

    public function test_forwarded_proto_is_honored_once_trusted(): void
    {
        config(['app.url' => 'http://localhost']);

        // No hay forma de re-ejecutar bootstrap/app.php dentro de un test
        // (el kernel y su pila de middleware ya están construidos), así que
        // se reproduce la config estática que `trustProxies()` deja atrás:
        // `Illuminate\Http\Middleware\TrustProxies` está SIEMPRE en la pila
        // global (getGlobalMiddleware() la incluye incondicionalmente), y su
        // handle() llama primero a `Request::setTrustedProxies([], ...)` y
        // recién después vuelve a confiar según `TrustProxies::at()` /
        // `withHeaders()`. Por eso invocar `request()->setTrustedProxies()`
        // directamente antes del `get()` no sirve: el middleware lo pisa en
        // cuanto corre la petición real. Configurar `TrustProxies::at('*')`
        // es exactamente lo que `$middleware->trustProxies(at: '*', ...)`
        // hace en bootstrap/app.php, así que esto ejercita el mecanismo real,
        // no una reimplementación paralela.
        TrustProxies::at('*');
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_PROTO);

        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/__trusted-proxy-probe');

        $response->assertJson(['secure' => true]);
    }
}
