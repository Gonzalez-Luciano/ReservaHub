# Fase 7 — API REST y Sanctum: plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Exponer reservas, servicios, empleados y disponibilidad como una API REST autenticada con tokens Sanctum, con respuestas en el envelope estándar del proyecto, paginación, límites de tasa y documentación OpenAPI.

**Architecture:** Controllers finos en `app/Http/Controllers/Api/` que delegan en las Actions y el `AvailabilityService` que ya existen; la autorización queda en las Policies actuales. El negocio se resuelve de dos maneras según quién llama: por el usuario del token (middleware `business` = `EnsureBusinessContext`) para staff, y por el `slug` de la URL (`BindPublicBusiness`) para clientes. Las rutas de reservas son compartidas y resuelven el scope dentro del controller con el trait `ResolvesBookingScope`. Toda respuesta —de éxito o de error— pasa por `App\Support\ApiResponse`.

**Tech Stack:** Laravel 13 sobre PHP 8.5, Laravel Sanctum (tokens de acceso personal), PostgreSQL 18, Redis, PHPUnit, `dedoc/scramble` para OpenAPI, Docker vía Laravel Sail.

**Spec:** `docs/superpowers/specs/2026-08-12-fase7-api-sanctum-design.md`

## Global Constraints

- Todo el texto de cara al usuario va en español (mensajes de error, mensajes del envelope, mensajes de validación).
- El envelope es siempre exactamente cuatro claves: `success`, `data`, `message`, `errors`. En éxito `errors` es `null`; en error `data` es `null`.
- Sin prefijo de versión en las rutas: `/api/...`, tal como `01-reservahub.md` §5.
- Sin abilities de Sanctum. La autorización es 100 % Policies + `role`.
- No se habilita `statefulApi()` ni se toca el guard `web`: la web Inertia sigue con sesión y no debe romperse.
- Las fechas de salida se serializan ISO-8601 en la zona horaria del negocio (`toIso8601String()` sobre un Carbon ya convertido), nunca en UTC.
- `Service` y `Booking` usan `BelongsToBusiness`: si no hay `Business` ligado en el contenedor, el scope global lanza `MissingBusinessContextException`. En cualquier consulta de un `customer` hay que usar `withoutGlobalScope(BusinessScope::class)`.
- Las reservas se crean desde la API con `source = 'api'` (la columna es un `string` libre).
- Los tests son clases PHPUnit en `Tests\Feature\Api\*` con `use RefreshDatabase;`, no Pest.
- Nunca usar `Event::fake()` sin argumentos: rompe el hook `creating` de `BelongsToBusiness`.
- Comandos siempre dentro del contenedor:
  ```bash
  docker compose exec laravel.test php artisan test --filter=NombreDelTest
  docker compose exec laravel.test vendor/bin/pint --test
  ```
- No entra en esta fase: pagos, webhooks, abilities, UI de tokens, endpoints de escritura de servicios/empleados/horarios, cambios en React.

## Estructura de archivos

**Crear:**

| Archivo | Responsabilidad |
|---|---|
| `routes/api.php` | Todas las rutas de la API |
| `app/Support/ApiResponse.php` | Construye el envelope de éxito, error y paginado |
| `app/Http/Requests/Api/LoginRequest.php` | Valida `email`, `password`, `device_name` |
| `app/Http/Requests/Api/AvailabilityRequest.php` | Valida `service_id`, `employee_id`, `date` |
| `app/Http/Requests/Api/BookingIndexRequest.php` | Valida filtros y `per_page` |
| `app/Http/Requests/Api/StoreBookingRequest.php` | Alta por staff (`customer_email` + datos) |
| `app/Http/Requests/Api/StoreCustomerBookingRequest.php` | Alta por cliente |
| `app/Http/Requests/Api/RescheduleBookingRequest.php` | Valida `starts_at` del PATCH |
| `app/Http/Resources/UserResource.php` | Usuario autenticado y cliente de una reserva |
| `app/Http/Resources/ServiceResource.php` | Servicio |
| `app/Http/Resources/EmployeeResource.php` | Empleado |
| `app/Http/Resources/SlotResource.php` | Slot libre de disponibilidad |
| `app/Http/Resources/BookingResource.php` | Reserva con relaciones opcionales |
| `app/Http/Controllers/Api/AuthController.php` | Login y logout |
| `app/Http/Controllers/Api/ServiceController.php` | Listado de servicios |
| `app/Http/Controllers/Api/EmployeeController.php` | Listado de empleados |
| `app/Http/Controllers/Api/AvailabilityController.php` | Slots libres |
| `app/Http/Controllers/Api/BookingController.php` | Reservas: index, show, store, update, cancel, confirm |
| `app/Http/Controllers/Api/Concerns/ResolvesBookingScope.php` | Scope de reservas según rol + relaciones a cargar |
| `docs/api.md` | Guía de uso de la API |
| `tests/Feature/Api/AuthTest.php` | Login, logout, rate limit, envelope de 401 |
| `tests/Feature/Api/ServicesTest.php` | Servicios por token y por slug |
| `tests/Feature/Api/EmployeesTest.php` | Empleados y filtro por servicio |
| `tests/Feature/Api/AvailabilityTest.php` | Slots y validación de parámetros |
| `tests/Feature/Api/BookingsIndexTest.php` | Listado, aislamiento por negocio, paginación |
| `tests/Feature/Api/BookingsWriteTest.php` | Alta, reprogramación, cancelación, confirmación |
| `tests/Feature/Api/EnvelopeTest.php` | Forma del envelope en 200/422/403/404 |

**Modificar:** `composer.json` (sanctum + scramble), `bootstrap/app.php` (grupo `api`, `throttleApi`, renderers de excepciones), `app/Models/User.php` (`HasApiTokens`), `app/Providers/AppServiceProvider.php` (rate limiters), `config/scramble.php` (generado), `CLAUDE.md` (sección de API).

---

### Task 1: Sanctum, envelope y autenticación por token

**Files:**
- Create: `app/Support/ApiResponse.php`
- Create: `app/Http/Requests/Api/LoginRequest.php`
- Create: `app/Http/Resources/UserResource.php`
- Create: `app/Http/Controllers/Api/AuthController.php`
- Create: `routes/api.php` (lo genera `install:api`, se reescribe)
- Modify: `bootstrap/app.php`
- Modify: `app/Models/User.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Api/AuthTest.php`

**Interfaces:**
- Produces:
  - `App\Support\ApiResponse::success(mixed $data = null, string $message = '', int $status = 200): JsonResponse`
  - `App\Support\ApiResponse::error(string $message, ?array $errors = null, int $status = 400): JsonResponse`
  - `App\Support\ApiResponse::paginated(AnonymousResourceCollection $collection, string $message = ''): JsonResponse`
  - `App\Http\Resources\UserResource`
  - Rutas con nombre `api.auth.login`, `api.auth.logout`
  - Rate limiters `api` (60/min) y `api-login` (5/min)

- [ ] **Step 1: Instalar Sanctum**

```bash
docker compose exec laravel.test php artisan install:api --no-interaction
```

Esto agrega `laravel/sanctum` a `composer.json`, publica la migración `personal_access_tokens`, crea `routes/api.php` y registra `api: __DIR__.'/../routes/api.php'` dentro de `withRouting()` en `bootstrap/app.php`. Verificar a ojo que `withRouting()` conservó `web`, `commands` y `health`.

Correr la migración:

```bash
docker compose exec laravel.test php artisan migrate --force
```

- [ ] **Step 2: Agregar `HasApiTokens` al modelo `User`**

En `app/Models/User.php`, importar y sumar el trait al `use` existente:

```php
use Laravel\Sanctum\HasApiTokens;
```

```php
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
```

- [ ] **Step 3: Escribir el test de autenticación (falla)**

`tests/Feature/Api/AuthTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_token_and_the_user(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->owner()->create([
            'business_id' => $business->id,
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'owner')
            ->assertJsonStructure(['success', 'data' => ['token', 'user'], 'message', 'errors']);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->customer()->create(['email' => 'cliente@example.com', 'password' => 'password']);

        $this->postJson('/api/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'incorrecta',
            'device_name' => 'phpunit',
        ])->assertStatus(401)->assertJsonPath('success', false)->assertJsonPath('data', null);
    }

    public function test_login_fails_for_an_inactive_user(): void
    {
        User::factory()->customer()->create([
            'email' => 'cliente@example.com',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertStatus(401);
    }

    public function test_login_fails_when_the_business_is_inactive(): void
    {
        $business = Business::factory()->create(['is_active' => false]);
        User::factory()->owner()->create([
            'business_id' => $business->id,
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertStatus(401);
    }

    public function test_login_requires_a_device_name(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['device_name']]);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->customer()->create();
        $current = $user->createToken('actual')->plainTextToken;
        $user->createToken('otro-dispositivo');

        $this->withHeader('Authorization', 'Bearer '.$current)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('otro-dispositivo', $user->tokens()->first()->name);
    }

    public function test_protected_routes_reject_requests_without_a_token(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No autenticado.');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'nadie@example.com',
                'password' => 'incorrecta',
                'device_name' => 'phpunit',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'nadie@example.com',
            'password' => 'incorrecta',
            'device_name' => 'phpunit',
        ])->assertStatus(429)->assertJsonPath('success', false);
    }
}
```

- [ ] **Step 4: Correr el test y verificar que falla**

```bash
docker compose exec laravel.test php artisan test --filter=AuthTest
```

Esperado: FAIL, las rutas `/api/auth/*` no existen (404).

- [ ] **Step 5: Crear `ApiResponse`**

`app/Support/ApiResponse.php`:

```php
<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'errors' => null,
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(string $message, ?array $errors = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    public static function paginated(AnonymousResourceCollection $collection, string $message = ''): JsonResponse
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $collection->resource;

        return static::success([
            'items' => $collection->collection,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], $message);
    }
}
```

- [ ] **Step 6: Mapear las excepciones al envelope**

En `bootstrap/app.php`, reemplazar el bloque `withExceptions` por:

```php
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

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') && ! config('app.debug')
                ? ApiResponse::error('Ocurrió un error inesperado.', null, 500)
                : null;
        });
    })->create();
```

Imports que hay que agregar arriba del archivo:

```php
use App\Exceptions\MissingBusinessContextException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
```

El orden importa: los renderers se evalúan en orden de registro y gana el primero que matchea, por eso el de `Throwable` va último y el de `HttpExceptionInterface` va anteúltimo.

Ese renderer genérico de `HttpExceptionInterface` cubre de una sola vez tres casos que de otro modo se escaparían: `abort(403)` de `EnsureBusinessContext` (que lanza un `HttpException` pelado, **no** un `AccessDeniedHttpException`), `NotFoundHttpException` de una ruta inexistente y `ThrottleRequestsException` del rate limiter (que extiende `TooManyRequestsHttpException`). `ModelNotFoundException` se registra antes porque no implementa `HttpExceptionInterface`: los `findOrFail()` de los controllers la lanzan tal cual.

`AuthorizationException` sí necesita su propio renderer registrado antes: los callbacks se evalúan sobre la excepción original, antes de que el framework la convierta en `AccessDeniedHttpException`.

- [ ] **Step 7: Activar el throttle del grupo `api`**

En `bootstrap/app.php`, dentro de `withMiddleware`, agregar como primera línea del closure:

```php
        $middleware->throttleApi();
```

- [ ] **Step 8: Definir los rate limiters**

En `app/Providers/AppServiceProvider.php`, dentro de `boot()`:

```php
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });
```

Imports:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
```

- [ ] **Step 9: Crear `LoginRequest` y `UserResource`**

`app/Http/Requests/Api/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'device_name.required' => 'Indicá un nombre de dispositivo para el token.',
        ];
    }
}
```

`app/Http/Resources/UserResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'business_id' => $this->business_id,
        ];
    }
}
```

- [ ] **Step 10: Crear `AuthController`**

`app/Http/Controllers/Api/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return ApiResponse::error('Estas credenciales no coinciden con nuestros registros.', null, 401);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Esta cuenta está desactivada.', null, 401);
        }

        if ($user->hasBusiness() && ! $user->business->is_active) {
            return ApiResponse::error('El negocio asociado a esta cuenta está desactivado.', null, 401);
        }

        $token = $user->createToken($request->validated('device_name'))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Sesión iniciada correctamente.');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Sanctum::actingAs() en tests entrega un TransientToken sin delete().
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(null, 'Sesión cerrada correctamente.');
    }
}
```

- [ ] **Step 11: Escribir `routes/api.php`**

Reemplazar lo que generó `install:api` por:

```php
<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api-login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});
```

- [ ] **Step 12: Correr el test y verificar que pasa**

```bash
docker compose exec laravel.test php artisan test --filter=AuthTest
```

Esperado: PASS, 8 tests.

- [ ] **Step 13: Verificar que la web no se rompió**

```bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test vendor/bin/pint
```

Esperado: toda la suite en verde. Si `Pint` reformatea algo, incluirlo en el commit.

- [ ] **Step 14: Commit**

```bash
git add composer.json composer.lock bootstrap/app.php routes/api.php app/Models/User.php app/Providers/AppServiceProvider.php app/Support app/Http/Requests/Api app/Http/Resources app/Http/Controllers/Api tests/Feature/Api database/migrations
git commit -m "feat: add Sanctum token auth and standard API response envelope"
```

---

### Task 2: Endpoints de servicios y empleados

**Files:**
- Create: `app/Http/Resources/ServiceResource.php`
- Create: `app/Http/Resources/EmployeeResource.php`
- Create: `app/Http/Controllers/Api/ServiceController.php`
- Create: `app/Http/Controllers/Api/EmployeeController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ServicesTest.php`
- Test: `tests/Feature/Api/EmployeesTest.php`

**Interfaces:**
- Consumes: `ApiResponse::success()`, rutas de Task 1.
- Produces: `ServiceResource`, `EmployeeResource`; rutas `api.services.index`, `api.employees.index`, `api.public.services.index`, `api.public.employees.index`.

- [ ] **Step 1: Escribir los tests (fallan)**

`tests/Feature/Api/ServicesTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_only_sees_services_of_its_own_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        Service::factory()->for($business)->create(['name' => 'Corte', 'is_active' => true]);
        Service::factory()->for($other)->create(['name' => 'Masaje', 'is_active' => true]);

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Corte')
            ->assertJsonStructure(['data' => [['id', 'name', 'duration_minutes', 'buffer_minutes', 'price', 'deposit_amount', 'is_active']]]);
    }

    public function test_inactive_services_are_hidden(): void
    {
        $business = Business::factory()->create();
        Service::factory()->for($business)->create(['is_active' => false]);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/services')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_reads_services_through_the_business_slug(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        Service::factory()->for($business)->create(['name' => 'Corte', 'is_active' => true]);
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/businesses/barberia-juan/services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Corte');
    }

    public function test_unknown_slug_returns_404_with_envelope(): void
    {
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/businesses/no-existe/services')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_services_require_a_token(): void
    {
        $this->getJson('/api/services')->assertStatus(401);
    }
}
```

`tests/Feature/Api/EmployeesTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_employees_of_the_business(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id, 'name' => 'Ana']);
        User::factory()->employee()->create(['business_id' => $business->id, 'is_active' => false]);
        User::factory()->employee()->create();

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee->id)
            ->assertJsonPath('data.0.name', 'Ana');
    }

    public function test_filters_employees_by_service(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        $service = Service::factory()->for($business)->create();
        $withService = User::factory()->employee()->create(['business_id' => $business->id]);
        User::factory()->employee()->create(['business_id' => $business->id]);
        $service->employees()->attach($withService->id);

        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson("/api/businesses/barberia-juan/employees?service_id={$service->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withService->id);
    }
}
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

```bash
docker compose exec laravel.test php artisan test --filter="ServicesTest|EmployeesTest"
```

Esperado: FAIL con 404, las rutas no existen.

- [ ] **Step 3: Crear los Resources**

`app/Http/Resources/ServiceResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'buffer_minutes' => $this->buffer_minutes,
            'price' => $this->price,
            'deposit_amount' => $this->deposit_amount,
            'is_active' => $this->is_active,
        ];
    }
}
```

`app/Http/Resources/EmployeeResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
        ];
    }
}
```

- [ ] **Step 4: Crear los controllers**

`app/Http/Controllers/Api/ServiceController.php` — el negocio ya está ligado en el contenedor por el middleware (`EnsureBusinessContext` o `BindPublicBusiness`), así que el scope global de `Service` filtra solo:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        $services = Service::where('is_active', true)->orderBy('name')->get();

        return ApiResponse::success(ServiceResource::collection($services));
    }
}
```

`app/Http/Controllers/Api/EmployeeController.php` — `users` no lleva scope global, se filtra explícito:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Business;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = Business::current();

        $query = User::where('business_id', $business->id)
            ->where('role', Role::Employee)
            ->where('is_active', true)
            ->orderBy('name');

        $serviceId = $request->query('service_id');

        if ($serviceId !== null && is_numeric($serviceId)) {
            $query->whereHas('services', fn ($services) => $services->where('services.id', (int) $serviceId));
        }

        return ApiResponse::success(EmployeeResource::collection($query->get()));
    }
}
```

- [ ] **Step 5: Agregar las rutas**

En `routes/api.php`, dentro del grupo `Route::name('api.')`, después del grupo `auth:sanctum` existente:

```php
    Route::middleware(['auth:sanctum', 'business'])->group(function () {
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    });

    Route::middleware(['auth:sanctum', BindPublicBusiness::class])
        ->prefix('businesses/{business:slug}')
        ->name('public.')
        ->group(function () {
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        });
```

Imports nuevos en `routes/api.php`:

```php
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Middleware\BindPublicBusiness;
```

- [ ] **Step 6: Correr los tests y verificar que pasan**

```bash
docker compose exec laravel.test php artisan test --filter="ServicesTest|EmployeesTest"
```

Esperado: PASS, 7 tests. Si `test_unknown_slug_returns_404_with_envelope` devuelve 500 en vez de 404, revisar que `BindPublicBusiness` corre después de `SubstituteBindings` y que su `firstOrFail()` lanza `ModelNotFoundException` (mapeada en Task 1).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources app/Http/Controllers/Api routes/api.php tests/Feature/Api
git commit -m "feat: expose services and employees over the API"
```

---

### Task 3: Endpoint de disponibilidad

**Files:**
- Create: `app/Http/Requests/Api/AvailabilityRequest.php`
- Create: `app/Http/Resources/SlotResource.php`
- Create: `app/Http/Controllers/Api/AvailabilityController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/AvailabilityTest.php`

**Interfaces:**
- Consumes: `ApiResponse`, `AvailabilityService::getAvailableSlots(Business, Service, User, CarbonImmutable, ?int)`.
- Produces: `SlotResource`; rutas `api.availability.index` y `api.public.availability.index`.

- [ ] **Step 1: Escribir el test (falla)**

`tests/Feature/Api/AvailabilityTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_free_slots_of_the_day(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan', 'timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0, 'is_active' => true]);
        $service->employees()->attach($employee->id);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $date = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date->toDateString()}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.starts_at', $date->setTime(9, 0)->toIso8601String())
            ->assertJsonPath('data.1.starts_at', $date->setTime(9, 30)->toIso8601String());
    }

    public function test_customer_reads_availability_through_the_slug(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan', 'timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 60, 'buffer_minutes' => 0, 'is_active' => true]);
        $service->employees()->attach($employee->id);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $date = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();
        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson("/api/businesses/barberia-juan/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date->toDateString()}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_missing_parameters_return_422(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/availability')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['service_id', 'employee_id', 'date']]);
    }

    public function test_employee_of_another_business_returns_404(): void
    {
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create();
        $stranger = User::factory()->employee()->create();
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson("/api/availability?service_id={$service->id}&employee_id={$stranger->id}&date=2026-09-07")
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

```bash
docker compose exec laravel.test php artisan test --filter=AvailabilityTest
```

Esperado: FAIL con 404.

- [ ] **Step 3: Crear el Form Request**

`app/Http/Requests/Api/AvailabilityRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
```

- [ ] **Step 4: Crear `SlotResource`**

`app/Http/Resources/SlotResource.php` — el recurso envuelto es el array `{starts_at, ends_at}` de `CarbonImmutable` que devuelve `AvailabilityService`, ya en la zona del negocio:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlotResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'starts_at' => $this->resource['starts_at']->toIso8601String(),
            'ends_at' => $this->resource['ends_at']->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 5: Crear el controller**

`app/Http/Controllers/Api/AvailabilityController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AvailabilityRequest;
use App\Http\Resources\SlotResource;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function index(AvailabilityRequest $request, AvailabilityService $availability): JsonResponse
    {
        $business = Business::current();

        $service = Service::findOrFail($request->validated('service_id'));

        $employee = User::where('business_id', $business->id)
            ->where('role', Role::Employee)
            ->findOrFail($request->validated('employee_id'));

        $date = CarbonImmutable::parse($request->validated('date'), $business->timezone);

        $slots = $availability->getAvailableSlots($business, $service, $employee, $date);

        return ApiResponse::success(SlotResource::collection($slots));
    }
}
```

`Service::findOrFail()` ya está acotado por el scope global al negocio ligado, así que un servicio ajeno también da 404.

- [ ] **Step 6: Agregar las rutas**

En `routes/api.php`, sumar en los dos grupos ya existentes (staff y slug):

```php
        Route::get('availability', [AvailabilityController::class, 'index'])->name('availability.index');
```

Import: `use App\Http\Controllers\Api\AvailabilityController;`

- [ ] **Step 7: Correr el test y verificar que pasa**

```bash
docker compose exec laravel.test php artisan test --filter=AvailabilityTest
```

Esperado: PASS, 4 tests.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Api app/Http/Resources app/Http/Controllers/Api routes/api.php tests/Feature/Api
git commit -m "feat: expose availability slots over the API"
```

---

### Task 4: Listado y detalle de reservas

**Files:**
- Create: `app/Http/Controllers/Api/Concerns/ResolvesBookingScope.php`
- Create: `app/Http/Resources/BookingResource.php`
- Create: `app/Http/Requests/Api/BookingIndexRequest.php`
- Create: `app/Http/Controllers/Api/BookingController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/BookingsIndexTest.php`

**Interfaces:**
- Consumes: `ApiResponse::success()`, `ApiResponse::paginated()`, `ServiceResource`, `EmployeeResource`, `UserResource`.
- Produces:
  - `ResolvesBookingScope::bookingQueryFor(User $user): Builder`
  - `ResolvesBookingScope::findBookingFor(User $user, int $bookingId): Booking`
  - `ResolvesBookingScope::bookingRelations(): array`
  - `BookingResource`
  - Rutas `api.bookings.index`, `api.bookings.show`

- [ ] **Step 1: Escribir el test (falla)**

`tests/Feature/Api/BookingsIndexTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(Business $business, ?User $customer = null, array $attributes = []): Booking
    {
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();

        return Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => ($customer ?? User::factory()->customer()->create())->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
        ], $attributes));
    }

    public function test_staff_sees_only_bookings_of_its_own_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $mine = $this->makeBooking($business);
        $this->makeBooking($other);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mine->id)
            ->assertJsonStructure(['data' => ['items', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]]);
    }

    public function test_customer_sees_only_their_own_bookings_across_businesses(): void
    {
        $first = Business::factory()->create();
        $second = Business::factory()->create();
        $customer = User::factory()->customer()->create();
        $this->makeBooking($first, $customer);
        $this->makeBooking($second, $customer);
        $this->makeBooking($first);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/bookings')->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_booking_payload_includes_relations_and_business_local_times(): void
    {
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $booking = $this->makeBooking($business, null, [
            'starts_at' => '2026-09-07 13:00:00',
            'ends_at' => '2026-09-07 13:30:00',
        ]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-09-07T10:00:00-03:00')
            ->assertJsonStructure(['data' => ['id', 'status', 'starts_at', 'ends_at', 'price', 'service' => ['id', 'name'], 'employee' => ['id', 'name'], 'customer' => ['id', 'name'], 'business' => ['id', 'slug', 'timezone']]]);
    }

    public function test_customer_cannot_read_someone_elses_booking(): void
    {
        $business = Business::factory()->create();
        $booking = $this->makeBooking($business);

        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson("/api/bookings/{$booking->id}")->assertStatus(404);
    }

    public function test_staff_of_another_business_cannot_read_the_booking(): void
    {
        $business = Business::factory()->create();
        $booking = $this->makeBooking($business);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => Business::factory()->create()->id]), [], 'sanctum');

        $this->getJson("/api/bookings/{$booking->id}")->assertStatus(404);
    }

    public function test_filters_by_status(): void
    {
        $business = Business::factory()->create();
        $this->makeBooking($business, null, ['status' => BookingStatus::Cancelled]);
        $confirmed = $this->makeBooking($business, null, ['status' => BookingStatus::Confirmed]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $confirmed->id);
    }

    public function test_paginates_with_per_page(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();
        Booking::factory()->count(3)->create([
            'business_id' => $business->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
        ]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.last_page', 2);
    }

    public function test_rejects_an_out_of_range_per_page(): void
    {
        $business = Business::factory()->create();
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings?per_page=500')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

```bash
docker compose exec laravel.test php artisan test --filter=BookingsIndexTest
```

Esperado: FAIL con 404.

- [ ] **Step 3: Crear el trait de scope**

`app/Http/Controllers/Api/Concerns/ResolvesBookingScope.php`:

```php
<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ResolvesBookingScope
{
    /**
     * Bookings visible to the acting user.
     *
     * Staff read their own business (the global BusinessScope filters once the
     * business is bound); a customer has no business of their own and reads
     * their bookings across every business, so the scope must be lifted.
     *
     * @return Builder<Booking>
     */
    protected function bookingQueryFor(User $user): Builder
    {
        if (in_array($user->role, Role::businessStaff(), true)) {
            abort_unless($user->hasBusiness(), 403);

            app()->instance(Business::class, $user->business);

            return Booking::query();
        }

        return Booking::withoutGlobalScope(BusinessScope::class)->where('customer_id', $user->id);
    }

    protected function findBookingFor(User $user, int $bookingId): Booking
    {
        return $this->bookingQueryFor($user)->findOrFail($bookingId);
    }

    /**
     * Relations every booking payload needs. `service` lifts the global scope
     * because a customer request has no business bound.
     *
     * @return array<int|string, mixed>
     */
    protected function bookingRelations(): array
    {
        return [
            'business:id,name,slug,timezone',
            'employee:id,name,email,is_active',
            'customer:id,name,email,role,business_id',
            'service' => fn ($query) => $query->withoutGlobalScope(BusinessScope::class),
        ];
    }
}
```

- [ ] **Step 4: Crear `BookingResource`**

`app/Http/Resources/BookingResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $this->business?->timezone ?? config('app.timezone');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->setTimezone($timezone)->toIso8601String(),
            'price' => $this->price,
            'deposit_amount' => $this->deposit_amount,
            'notes' => $this->notes,
            'source' => $this->source,
            'business' => $this->whenLoaded('business', fn () => [
                'id' => $this->business->id,
                'name' => $this->business->name,
                'slug' => $this->business->slug,
                'timezone' => $this->business->timezone,
            ]),
            'service' => ServiceResource::make($this->whenLoaded('service')),
            'employee' => EmployeeResource::make($this->whenLoaded('employee')),
            'customer' => UserResource::make($this->whenLoaded('customer')),
        ];
    }
}
```

- [ ] **Step 5: Crear `BookingIndexRequest`**

`app/Http/Requests/Api/BookingIndexRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
```

- [ ] **Step 6: Crear `BookingController` con `index` y `show`**

`app/Http/Controllers/Api/BookingController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesBookingScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookingIndexRequest;
use App\Http\Resources\BookingResource;
use App\Models\Business;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ResolvesBookingScope;

    public function index(BookingIndexRequest $request): JsonResponse
    {
        $query = $this->bookingQueryFor($request->user())->with($this->bookingRelations());

        $timezone = Business::current()?->timezone ?? config('app.timezone');

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->validated('from')) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }

        if ($to = $request->validated('to')) {
            $query->where('starts_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc());
        }

        if ($employeeId = $request->validated('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        $bookings = $query->orderByDesc('starts_at')->paginate($request->perPage());

        return ApiResponse::paginated(BookingResource::collection($bookings));
    }

    public function show(Request $request, int $booking): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('view', $model);

        return ApiResponse::success(BookingResource::make($model->load($this->bookingRelations())));
    }
}
```

`bookingQueryFor()` liga el negocio del staff antes de que se lea `Business::current()`, así que el orden de las líneas importa: primero la query, después el timezone.

- [ ] **Step 7: Agregar las rutas compartidas**

En `routes/api.php`, dentro del grupo `Route::middleware('auth:sanctum')` que ya contiene el logout:

```php
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show')->whereNumber('booking');
```

Import: `use App\Http\Controllers\Api\BookingController;`

Estas rutas **no** llevan el middleware `business`: un `customer` no tiene negocio y el middleware lo rechazaría con 403.

- [ ] **Step 8: Correr el test y verificar que pasa**

```bash
docker compose exec laravel.test php artisan test --filter=BookingsIndexTest
```

Esperado: PASS, 8 tests.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Api app/Http/Resources app/Http/Requests/Api routes/api.php tests/Feature/Api
git commit -m "feat: list and show bookings over the API"
```

---

### Task 5: Escrituras de reservas

**Files:**
- Create: `app/Http/Requests/Api/StoreBookingRequest.php`
- Create: `app/Http/Requests/Api/StoreCustomerBookingRequest.php`
- Create: `app/Http/Requests/Api/RescheduleBookingRequest.php`
- Modify: `app/Http/Controllers/Api/BookingController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/BookingsWriteTest.php`
- Test: `tests/Feature/Api/EnvelopeTest.php`

**Interfaces:**
- Consumes: `CreateBooking::handle(Business $business, array $data, User $actingUser): Booking` (claves: `customer_id`, `employee_id`, `service_id`, `starts_at`, `source`, `notes`), `RescheduleBooking::handle(Booking $booking, array{starts_at: string} $data, User $actingUser): Booking`, `CancelBooking::handle(Booking $booking, User $actingUser): Booking`, `ConfirmBooking::handle(Booking $booking, User $actingUser): Booking`, `ResolvesBookingScope` de Task 4.
- Produces: rutas `api.bookings.store`, `api.public.bookings.store`, `api.bookings.update`, `api.bookings.cancel`, `api.bookings.confirm`.

- [ ] **Step 1: Escribir los tests (fallan)**

`tests/Feature/Api/BookingsWriteTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingsWriteTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $employee;

    private Service $service;

    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->business = Business::factory()->create([
            'slug' => 'barberia-juan',
            'timezone' => 'UTC',
            'cancellation_hours' => 24,
        ]);

        $this->employee = User::factory()->employee()->create(['business_id' => $this->business->id]);

        $this->service = Service::factory()->for($this->business)->create([
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'deposit_amount' => 0,
            'is_active' => true,
        ]);

        $this->service->employees()->attach($this->employee->id);

        Schedule::factory()->create([
            'business_id' => $this->business->id,
            'employee_id' => $this->employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $this->monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();
    }

    public function test_staff_creates_a_booking_for_a_customer(): void
    {
        $customer = User::factory()->customer()->create(['email' => 'cliente@example.com']);
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $this->business->id]), [], 'sanctum');

        $this->postJson('/api/bookings', [
            'customer_email' => 'cliente@example.com',
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
            'notes' => 'Primera vez',
        ])->assertStatus(201)->assertJsonPath('success', true)->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('bookings', [
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'source' => 'api',
            'notes' => 'Primera vez',
        ]);
    }

    public function test_customer_creates_a_booking_through_the_slug(): void
    {
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson('/api/businesses/barberia-juan/bookings', [
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
        ])->assertStatus(201);

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'business_id' => $this->business->id,
            'source' => 'api',
        ]);
    }

    public function test_a_taken_slot_is_rejected_with_422(): void
    {
        $customer = User::factory()->customer()->create();
        Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson('/api/businesses/barberia-juan/bookings', [
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['starts_at']]);
    }

    public function test_staff_user_cannot_book_through_the_customer_route(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $this->business->id]), [], 'sanctum');

        $this->postJson('/api/businesses/barberia-juan/bookings', [
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
        ])->assertStatus(403);
    }

    public function test_customer_reschedules_their_booking_with_patch(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->patchJson("/api/bookings/{$booking->id}", [
            'starts_at' => $this->monday->setTime(10, 0)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.starts_at', $this->monday->setTime(10, 0)->toIso8601String());
    }

    public function test_reschedule_to_a_time_outside_working_hours_fails(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->patchJson("/api/bookings/{$booking->id}", [
            'starts_at' => $this->monday->setTime(20, 0)->toIso8601String(),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['starts_at']]);
    }

    public function test_customer_cancels_their_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_customer_cannot_cancel_past_the_cancellation_window(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => CarbonImmutable::now('UTC')->addHours(2),
            'ends_at' => CarbonImmutable::now('UTC')->addHours(2)->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/cancel")->assertStatus(403);
    }

    public function test_staff_confirms_a_pending_booking(): void
    {
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Pending,
        ]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $this->business->id]), [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_customer_cannot_confirm_a_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Pending,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/confirm")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
```

Nota sobre el último test: `POST /api/bookings/{id}/confirm` está en el grupo staff, con el middleware `business`. `EnsureBusinessContext` hace `abort(403)` para un `customer` antes de llegar al controller, y el renderer de `HttpExceptionInterface` de Task 1 lo convierte en el envelope con 403. Por eso 403 y no 404.

`tests/Feature/Api/EnvelopeTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_responses_have_exactly_the_four_keys(): void
    {
        $business = Business::factory()->create();
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $payload = $this->getJson('/api/services')->assertOk()->json();

        $this->assertSame(['success', 'data', 'message', 'errors'], array_keys($payload));
        $this->assertTrue($payload['success']);
        $this->assertNull($payload['errors']);
    }

    public function test_validation_errors_use_the_envelope(): void
    {
        $business = Business::factory()->create();
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $payload = $this->getJson('/api/availability')->assertStatus(422)->json();

        $this->assertSame(['success', 'data', 'message', 'errors'], array_keys($payload));
        $this->assertFalse($payload['success']);
        $this->assertNull($payload['data']);
        $this->assertArrayHasKey('service_id', $payload['errors']);
    }

    public function test_not_found_uses_the_envelope(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson('/api/bookings/999999')
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => 'Recurso no encontrado.',
                'errors' => null,
            ]);
    }

    public function test_forbidden_uses_the_envelope(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson('/api/services')
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => 'No tenés permiso para realizar esta acción.',
                'errors' => null,
            ]);
    }
}
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

```bash
docker compose exec laravel.test php artisan test --filter="BookingsWriteTest|EnvelopeTest"
```

Esperado: FAIL con 404 en las rutas de escritura.

- [ ] **Step 3: Crear los Form Requests**

`app/Http/Requests/Api/StoreBookingRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_email' => [
                'required',
                'email',
                Rule::exists('users', 'email')->where(fn ($query) => $query->where('role', Role::Customer->value)),
            ],
            'employee_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_email.exists' => 'No existe un cliente registrado con ese email.',
        ];
    }
}
```

`app/Http/Requests/Api/StoreCustomerBookingRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
        ];
    }
}
```

`app/Http/Requests/Api/RescheduleBookingRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
        ];
    }
}
```

La autorización real vive en las Policies, invocadas desde el controller: los Form Requests solo validan forma.

- [ ] **Step 4: Agregar los métodos de escritura al `BookingController`**

En `app/Http/Controllers/Api/BookingController.php`, después de `show()`:

```php
    public function store(StoreBookingRequest $request, CreateBooking $action): JsonResponse
    {
        $business = Business::current();

        $this->authorize('createByStaff', $business);

        $customer = User::where('role', Role::Customer)
            ->where('email', $request->validated('customer_email'))
            ->firstOrFail();

        $booking = $action->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'api',
            'notes' => $request->validated('notes'),
        ], $request->user());

        return ApiResponse::success(
            BookingResource::make($booking->load($this->bookingRelations())),
            'Reserva creada correctamente.',
            201,
        );
    }

    public function storeForCustomer(StoreCustomerBookingRequest $request, Business $business, CreateBooking $action): JsonResponse
    {
        $this->authorize('createByCustomer', Booking::class);

        $booking = $action->handle($business, [
            'customer_id' => $request->user()->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'api',
            'notes' => null,
        ], $request->user());

        return ApiResponse::success(
            BookingResource::make($booking->load($this->bookingRelations())),
            'Reserva creada correctamente.',
            201,
        );
    }

    public function update(RescheduleBookingRequest $request, int $booking, RescheduleBooking $action): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('reschedule', $model);

        $updated = $action->handle($model, ['starts_at' => $request->validated('starts_at')], $request->user());

        return ApiResponse::success(
            BookingResource::make($updated->load($this->bookingRelations())),
            'Reserva reprogramada correctamente.',
        );
    }

    public function cancel(Request $request, int $booking, CancelBooking $action): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('cancel', $model);

        $cancelled = $action->handle($model, $request->user());

        return ApiResponse::success(
            BookingResource::make($cancelled->load($this->bookingRelations())),
            'Reserva cancelada correctamente.',
        );
    }

    public function confirm(Request $request, int $booking, ConfirmBooking $action): JsonResponse
    {
        $model = $this->findBookingFor($request->user(), $booking);

        $this->authorize('confirm', $model);

        $confirmed = $action->handle($model, $request->user());

        return ApiResponse::success(
            BookingResource::make($confirmed->load($this->bookingRelations())),
            'Reserva confirmada correctamente.',
        );
    }
```

Imports que se suman al controller:

```php
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Enums\Role;
use App\Http\Requests\Api\RescheduleBookingRequest;
use App\Http\Requests\Api\StoreBookingRequest;
use App\Http\Requests\Api\StoreCustomerBookingRequest;
use App\Models\Booking;
use App\Models\User;
```

- [ ] **Step 5: Agregar las rutas de escritura**

En el grupo staff (`['auth:sanctum', 'business']`):

```php
        Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm')->whereNumber('booking');
```

En el grupo por slug (`['auth:sanctum', BindPublicBusiness::class]`):

```php
            Route::post('bookings', [BookingController::class, 'storeForCustomer'])->name('bookings.store');
```

En el grupo compartido (`auth:sanctum`, junto a index y show):

```php
        Route::patch('bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update')->whereNumber('booking');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel')->whereNumber('booking');
```

- [ ] **Step 6: Correr los tests y verificar que pasan**

```bash
docker compose exec laravel.test php artisan test --filter="BookingsWriteTest|EnvelopeTest"
```

Esperado: PASS, 15 tests.

- [ ] **Step 7: Correr la suite entera**

```bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test vendor/bin/pint
```

Esperado: verde. Las notificaciones de reserva siguen disparándose desde las Actions, así que los tests de Fase 6 no deberían cambiar.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api app/Http/Requests/Api routes/api.php tests/Feature/Api
git commit -m "feat: create, reschedule, cancel and confirm bookings over the API"
```

---

### Task 6: Documentación OpenAPI y guía de uso

**Files:**
- Modify: `composer.json`
- Create: `config/scramble.php` (publicado)
- Create: `docs/api.md`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: todas las rutas de las Tasks 1–5.
- Produces: UI en `/docs/api`, JSON en `/docs/api.json`, `docs/api.md`.

- [ ] **Step 1: Instalar Scramble**

```bash
docker compose exec laravel.test composer require dedoc/scramble --dev
docker compose exec laravel.test php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider" --tag="scramble-config"
```

- [ ] **Step 2: Configurar el prefijo**

En `config/scramble.php`, dejar `'api_path' => 'api'` (es el valor por defecto) y verificar que `'api_domain' => null`. No tocar el gate: por defecto Scramble solo expone `/docs/api` en entorno local, que es lo que queremos.

- [ ] **Step 3: Verificar que el documento se genera**

```bash
docker compose exec laravel.test php artisan scramble:export --path=storage/app/api.json
docker compose exec laravel.test php -r "\$d = json_decode(file_get_contents('storage/app/api.json'), true); echo count(\$d['paths']), PHP_EOL; echo implode(PHP_EOL, array_keys(\$d['paths'])), PHP_EOL;"
```

Esperado: aparecen `/api/auth/login`, `/api/auth/logout`, `/api/services`, `/api/employees`, `/api/availability`, `/api/bookings`, `/api/bookings/{booking}`, `/api/bookings/{booking}/cancel`, `/api/bookings/{booking}/confirm`, `/api/businesses/{business}/services`, `/api/businesses/{business}/employees`, `/api/businesses/{business}/availability`, `/api/businesses/{business}/bookings`. Borrar el archivo exportado después (`storage/app/` no se versiona).

- [ ] **Step 4: Escribir `docs/api.md`**

```markdown
# API de ReservaHub

REST sobre `/api`, autenticada con tokens personales de Laravel Sanctum. Todas las respuestas usan el mismo envelope:

​```json
{ "success": true, "data": {}, "message": "", "errors": null }
​```

En error, `success` es `false`, `data` es `null` y `errors` trae el detalle de validación cuando lo hay.

## Autenticación

​```bash
curl -X POST http://localhost/api/auth/login \
  -H 'Accept: application/json' \
  -d 'email=owner@example.com&password=password&device_name=cli'
​```

Devuelve `data.token`. Mandalo en cada petición siguiente:

​```bash
curl http://localhost/api/services -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
​```

`POST /api/auth/logout` revoca solo el token usado.

## Endpoints

| Método | Ruta | Quién | Qué hace |
|---|---|---|---|
| POST | `/api/auth/login` | público | Emite un token |
| POST | `/api/auth/logout` | autenticado | Revoca el token actual |
| GET | `/api/services` | staff | Servicios activos del negocio del token |
| GET | `/api/employees` | staff | Empleados activos; `?service_id=` filtra |
| GET | `/api/availability` | staff | Slots libres; `?service_id=&employee_id=&date=YYYY-MM-DD` |
| POST | `/api/bookings` | staff | Crea reserva para un cliente (`customer_email`) |
| POST | `/api/bookings/{id}/confirm` | staff | Confirma una reserva pendiente |
| GET | `/api/businesses/{slug}/services` | cliente | Servicios del negocio |
| GET | `/api/businesses/{slug}/employees` | cliente | Empleados del negocio |
| GET | `/api/businesses/{slug}/availability` | cliente | Slots libres |
| POST | `/api/businesses/{slug}/bookings` | cliente | Crea su propia reserva |
| GET | `/api/bookings` | ambos | Listado paginado; filtros `status`, `from`, `to`, `employee_id`, `per_page` |
| GET | `/api/bookings/{id}` | ambos | Detalle |
| PATCH | `/api/bookings/{id}` | ambos | Reprograma (`starts_at`) |
| POST | `/api/bookings/{id}/cancel` | ambos | Cancela |

Staff ve las reservas de su negocio; un cliente ve solo las propias, de cualquier negocio.

## Paginación

`GET /api/bookings` devuelve `data.items` y `data.meta` (`current_page`, `per_page`, `total`, `last_page`). `per_page` por defecto 15, máximo 100.

## Límites de tasa

60 peticiones por minuto por usuario. `POST /api/auth/login`, 5 por minuto por email + IP. Al excederlos, 429 con el envelope.

## Códigos de error

| Código | Cuándo |
|---|---|
| 401 | Sin token, token inválido, credenciales incorrectas, cuenta o negocio inactivo |
| 403 | El rol no puede realizar la acción (incluye cancelar fuera del plazo) |
| 404 | Recurso inexistente o de otro negocio |
| 422 | Validación o regla de negocio (horario ocupado, fuera de horario laboral, estado inválido) |
| 429 | Límite de tasa superado |

## Ejemplo completo

​```bash
TOKEN=$(curl -s -X POST http://localhost/api/auth/login \
  -H 'Accept: application/json' \
  -d 'email=cliente@example.com&password=password&device_name=cli' | jq -r '.data.token')

curl -s "http://localhost/api/businesses/barberia-juan/availability?service_id=1&employee_id=2&date=2026-09-07" \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -s -X POST http://localhost/api/businesses/barberia-juan/bookings \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" \
  -d 'service_id=1&employee_id=2&starts_at=2026-09-07T09:00:00-03:00'
​```

## OpenAPI

Con la app corriendo en local, la especificación navegable está en `http://localhost/docs/api` y el JSON en `http://localhost/docs/api.json`, generados por `dedoc/scramble` a partir de las rutas, los Form Requests y los Resources.
```

Los caracteres `​` invisibles delante de cada triple backtick anidado son solo del plan: en `docs/api.md` escribir triple backtick normal.

- [ ] **Step 5: Documentar la API en `CLAUDE.md`**

Agregar una sección después de "Package manager: pnpm, not npm":

```markdown
## API REST (Fase 7)

REST bajo `/api`, sin versión, autenticada con tokens Sanctum (`POST /api/auth/login` con `email`, `password`, `device_name`). Sin abilities: autoriza el rol vía Policies.

El negocio se resuelve de dos formas: staff → middleware `business` (`EnsureBusinessContext`) sobre el usuario del token; cliente → `/api/businesses/{slug}/...` con `BindPublicBusiness`. Las rutas de reservas (`GET|PATCH /api/bookings*`, `cancel`) son compartidas y **no** llevan el middleware `business`: el trait `App\Http\Controllers\Api\Concerns\ResolvesBookingScope` liga el negocio si el usuario es staff, o levanta `BusinessScope` si es customer.

Toda respuesta pasa por `App\Support\ApiResponse` y tiene exactamente `{success, data, message, errors}`; las excepciones se mapean a ese mismo envelope en `bootstrap/app.php`. Los listados paginados van en `data.items` + `data.meta`.

Documentación: `docs/api.md` y OpenAPI en `/docs/api` (dedoc/scramble, solo en local).
```

- [ ] **Step 6: Verificación final**

```bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test php artisan route:list --path=api
```

Esperado: suite completa en verde, Pint sin diferencias, y `route:list` mostrando las 13 rutas de la tabla de `docs/api.md`.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock config/scramble.php docs/api.md CLAUDE.md
git commit -m "docs: document the REST API with OpenAPI and a usage guide"
```

---

## Verificación de la fase

Con las seis tareas terminadas, esto es lo que debe valer:

- `docker compose exec laravel.test php artisan test` en verde, incluidos los ~45 tests nuevos de `tests/Feature/Api/`.
- `docker compose exec laravel.test vendor/bin/pint --test` sin diferencias.
- La web Inertia sigue funcionando con sesión (login, dashboard, flujo público de reserva).
- `http://localhost/docs/api` renderiza la especificación.
