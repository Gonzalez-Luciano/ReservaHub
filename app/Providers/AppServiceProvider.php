<?php

namespace App\Providers;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Exceptions\MissingWebhookSecretException;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Un único proveedor aprobado en la Fase 9. Sin manager ni selección
        // dinámica en runtime: un adapter real futuro reemplaza este binding.
        // La clase concreta también se liga porque sus dependencias son escalares
        // y el contenedor no puede autoresolverlas (los tests la piden por tipo).
        $this->app->singleton(SimulatedPaymentGateway::class, function () {
            $secret = config('payments.simulated.webhook_secret');

            // Falla cerrado (mismo patrón que UserAccessRevoker): un secreto
            // ausente firmaría y verificaría con una clave HMAC vacía, así
            // que el endpoint aceptaría cualquier firma forjada por quien
            // sepa que la clave está vacía. No hay binding degradado.
            if (! is_string($secret) || $secret === '') {
                throw new MissingWebhookSecretException(
                    'PAYMENTS_SIMULATED_WEBHOOK_SECRET no está configurado.'
                );
            }

            return new SimulatedPaymentGateway(
                secret: $secret,
                toleranceSeconds: (int) config('payments.webhook_tolerance_seconds'),
            );
        });

        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(SimulatedPaymentGateway::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('payment-webhooks', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
