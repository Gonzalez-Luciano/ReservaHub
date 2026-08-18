<?php

use App\Exceptions\MissingBusinessContextException;
use App\Http\Middleware\EnsureBusinessContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'business' => EnsureBusinessContext::class,
        ]);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureBusinessContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('Los datos enviados no son válidos.', $e->errors(), 422)
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('No autenticado.', null, 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('No tenés permiso para realizar esta acción.', null, 403)
                : null;
        });

        $exceptions->render(function (MissingBusinessContextException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('No hay un negocio asociado a esta petición.', null, 403)
                : null;
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('Recurso no encontrado.', null, 404)
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            return ApiResponse::error(match ($status) {
                401 => 'No autenticado.',
                403 => 'No tenés permiso para realizar esta acción.',
                404 => 'Recurso no encontrado.',
                429 => 'Demasiadas peticiones. Probá de nuevo más tarde.',
                default => 'Ocurrió un error inesperado.',
            }, null, $status);
        });

        $exceptions->render(function (GatewayUnavailableException $e, Request $request) {
            report($e);

            return $request->is('api/*')
                ? ApiResponse::error('No se pudo iniciar el pago. Probá de nuevo en unos minutos.', null, 502)
                : back()->withErrors(['payment' => 'No se pudo iniciar el pago. Probá de nuevo en unos minutos.']);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') && ! config('app.debug')
                ? ApiResponse::error('Ocurrió un error inesperado.', null, 500)
                : null;
        });
    })->create();
