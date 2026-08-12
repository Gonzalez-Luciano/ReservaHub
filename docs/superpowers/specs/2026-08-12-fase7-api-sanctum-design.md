# Fase 7 — API REST y Sanctum

Diseño validado el 2026-08-12. Implementa el punto "Fase 7 — API y Sanctum" de `01-reservahub.md`.

## Objetivo

Exponer el dominio de reservas como una API REST autenticada con tokens Sanctum, consumible tanto por el personal de un negocio (owner, admin, employee) como por clientes finales, con respuestas envueltas en el formato estándar del proyecto, paginación, límites de tasa y documentación OpenAPI navegable.

## Estado de partida

Fases 0–6 terminadas. Lo que ya existe y esta fase reusa sin modificar:

- **Tenancy**: `BusinessScope` (scope global sobre modelos con `BelongsToBusiness`), `EnsureBusinessContext` (alias `business`, liga el negocio del usuario staff autenticado) y `BindPublicBusiness` (liga el negocio por `slug` de la URL). Ambos middlewares hacen `app()->instance(Business::class, $business)`.
- **Actions**: `CreateBooking`, `CancelBooking`, `ConfirmBooking`, `RescheduleBooking`, `CompleteBooking`, `MarkNoShow`. Todas validan reglas de negocio y lanzan `ValidationException` con mensajes en español; `CreateBooking` y `RescheduleBooking` revalidan disponibilidad dentro de una transacción con `pg_advisory_xact_lock`.
- **`AvailabilityService::getAvailableSlots(Business, Service, User $employee, CarbonImmutable $date, ?int $excludeBookingId)`**.
- **Policies**: `BookingPolicy` (incluye el plazo de cancelación en `cancel`/`reschedule`), `ServicePolicy`, `SchedulePolicy`, `TimeOffPolicy`, `UserPolicy`, `BusinessPolicy`, `EmployeeInvitationPolicy`.
- **Eventos y notificaciones** disparados desde las Actions: crear una reserva por API notifica igual que por web, sin trabajo extra.
- `bootstrap/app.php` ya declara `$exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'))`.

Lo que falta y esta fase agrega:

- No existe `routes/api.php` ni está registrado el grupo `api` en `bootstrap/app.php`.
- `laravel/sanctum` no está en `composer.json`; no hay tabla `personal_access_tokens` ni trait `HasApiTokens` en `User`.
- No existe `app/Http/Controllers/Api/`, `app/Http/Requests/Api/` ni `app/Http/Resources/`.
- No hay envelope de respuesta ni mapeo de excepciones al envelope.
- No hay límites de tasa configurados para API.
- No hay documentación de API.

## Alcance

Entra:

- Instalación y configuración de Sanctum con tokens de acceso personal.
- `POST /api/auth/login` y `POST /api/auth/logout`.
- Endpoints de lectura: servicios, empleados, disponibilidad.
- Endpoints de reservas: listado paginado, detalle, creación, reprogramación, cancelación, confirmación.
- Resources para dar forma al JSON.
- Envelope `{success, data, message, errors}` en éxito y error.
- Paginación y límites de tasa.
- Documentación OpenAPI generada más una guía breve en Markdown.
- Tests de feature en `tests/Feature/Api/`.

No entra:

- `POST /api/payments` y `POST /api/webhooks/payments/{provider}` (§5 de `01-reservahub.md`): pertenecen a Fase 8 y se implementan enteros ahí.
- Abilities/scopes de Sanctum. La autorización queda 100 % en las Policies existentes.
- Pantalla en el dashboard para crear o revocar tokens. El único emisor es `POST /api/auth/login`.
- Autenticación SPA por cookie (`statefulApi()`). La web Inertia sigue usando sesión y no consume la API.
- Endpoints de escritura sobre servicios, empleados, horarios o licencias. La API de esta fase es de reservas; la gestión sigue en el dashboard.
- Cambios en el frontend React.

## Autenticación

`laravel/sanctum` con tokens de acceso personal (Bearer). `User` suma el trait `HasApiTokens`.

`POST /api/auth/login` recibe `email`, `password` y `device_name` (string obligatorio, hasta 255 caracteres, sirve de nombre del token). Verifica credenciales con `Auth::validate`-equivalente sin iniciar sesión (no hay estado). Rechaza con 401 y mensaje en español si:

- las credenciales no coinciden,
- el usuario tiene `is_active = false`,
- el usuario es staff y su negocio tiene `is_active = false`.

En éxito devuelve `{ token, user: UserResource }`. El token plano se muestra una sola vez. No se exige email verificado, para reflejar la web: ni `routes/dashboard.php` ni `routes/public.php` usan el middleware `verified`.

`POST /api/auth/logout` (autenticado) revoca solo `$request->user()->currentAccessToken()`.

## Tenancy en la API

Dos formas de resolver el negocio, según quién llama. Ninguna inventa mecanismo nuevo: cada una reusa un middleware existente.

**Staff** — el negocio sale del usuario del token. Grupo con `['auth:sanctum', 'business']`; `EnsureBusinessContext` ya aborta 403 si el usuario no es staff, no tiene negocio, o el usuario o el negocio están inactivos, y liga el `Business` en el contenedor para que `BusinessScope` filtre solo.

**Cliente** — el negocio sale del `slug` en la URL: `/api/businesses/{business:slug}/...` con `BindPublicBusiness`, igual que las rutas públicas web. Un `customer` tiene `business_id = null` y puede reservar en varios negocios.

Las rutas de reservas propias (`GET /api/bookings`, `GET|PATCH /api/bookings/{booking}`, `POST /api/bookings/{booking}/cancel`) son compartidas: llevan solo `auth:sanctum` y el controller ramifica por rol, tal como hace hoy `MyBookingsController`:

- staff → el controller liga el negocio del usuario (`app()->instance(Business::class, $user->business)`) y consulta normal, dejando que `BusinessScope` filtre;
- customer → `Booking::withoutGlobalScope(BusinessScope::class)->where('customer_id', $user->id)`.

Para evitar que el scope global explote con `MissingBusinessContextException` cuando un customer entra a una ruta compartida, esas rutas **no** usan el middleware `business`. El controller liga el negocio del usuario staff (`app()->instance(Business::class, $user->business)`) al principio de la acción cuando el rol es staff, y usa `withoutGlobalScope` cuando es customer. Esa decisión vive en un único trait `App\Http\Controllers\Api\Concerns\ResolvesBookingScope` para no repetirla en cada método.

`BookingPolicy` sigue siendo la autoridad final: `view`, `cancel`, `reschedule` ya contemplan tanto al dueño de la reserva como al staff del negocio; `confirm` es solo staff.

## Rutas

Sin prefijo de versión, tal como los escribe `01-reservahub.md` §5.

```text
# público
POST   /api/auth/login

# staff (auth:sanctum + business)
GET    /api/services
GET    /api/employees
GET    /api/availability?service_id=&employee_id=&date=
POST   /api/bookings
POST   /api/bookings/{booking}/confirm

# cliente, negocio por slug (BindPublicBusiness)
GET    /api/businesses/{business:slug}/services
GET    /api/businesses/{business:slug}/employees?service_id=
GET    /api/businesses/{business:slug}/availability?service_id=&employee_id=&date=
POST   /api/businesses/{business:slug}/bookings

# compartidas staff + cliente (auth:sanctum)
GET    /api/bookings?status=&from=&to=&employee_id=&per_page=
GET    /api/bookings/{booking}
PATCH  /api/bookings/{booking}
POST   /api/bookings/{booking}/cancel
POST   /api/auth/logout
```

`POST /api/auth/logout` va en el grupo `auth:sanctum` general, no en el de staff: un customer también debe poder cerrar sesión.

Semántica de cada endpoint de escritura:

| Endpoint | Action | Notas |
|---|---|---|
| `POST /api/bookings` (staff) | `CreateBooking` | Body: `customer_email`, `employee_id`, `service_id`, `starts_at`, `notes?`. `source = 'api'`. |
| `POST /api/businesses/{slug}/bookings` (cliente) | `CreateBooking` | Body: `employee_id`, `service_id`, `starts_at`. El cliente es el usuario autenticado. `source = 'api'`. |
| `PATCH /api/bookings/{booking}` | `RescheduleBooking` | Body: `starts_at`. Reprograma; no edita notas ni estado. |
| `POST /api/bookings/{booking}/cancel` | `CancelBooking` | Aplica el plazo `cancellation_hours` cuando quien llama es el cliente. |
| `POST /api/bookings/{booking}/confirm` | `ConfirmBooking` | Solo staff. |

`completed` y `no_show` no se exponen: `01-reservahub.md` §5 no los lista.

`GET /api/availability` devuelve los slots libres del día pedido para un servicio y un empleado, delegando en `AvailabilityService`. La fecha se interpreta como día calendario en la zona horaria del negocio, que es exactamente el contrato del servicio.

`GET /api/employees` devuelve los empleados activos del negocio; con `?service_id=` los filtra por los que prestan ese servicio.

## Envelope de respuesta

Toda respuesta, de éxito o de error, tiene la misma forma:

```json
{
  "success": true,
  "data": {},
  "message": "Reserva creada correctamente.",
  "errors": null
}
```

Lo produce un helper `App\Support\ApiResponse`:

- `ApiResponse::success(mixed $data = null, string $message = '', int $status = 200): JsonResponse`
- `ApiResponse::error(string $message, ?array $errors = null, int $status = 400): JsonResponse`
- `ApiResponse::paginated(AnonymousResourceCollection $collection, string $message = ''): JsonResponse`

En éxito, `errors` es `null`. En error, `data` es `null` y `errors` es un mapa `campo => [mensajes]` cuando hay detalle de validación, o `null` cuando no lo hay.

Las colecciones paginadas se serializan como:

```json
{
  "success": true,
  "data": {
    "items": [],
    "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 }
  },
  "message": "",
  "errors": null
}
```

Se elige `data.items` + `data.meta` en vez de los `links`/`meta` de nivel superior que agrega Laravel, para no romper la forma fija de cuatro claves que exige el spec.

## Errores

En `bootstrap/app.php`, dentro de `withExceptions`, se registran renderers que solo actúan sobre peticiones a `api/*`:

| Excepción | HTTP | `message` | `errors` |
|---|---|---|---|
| `ValidationException` | 422 | "Los datos enviados no son válidos." | `$e->errors()` |
| `AuthenticationException` | 401 | "No autenticado." | `null` |
| `AuthorizationException` / `AccessDeniedHttpException` | 403 | "No tenés permiso para realizar esta acción." | `null` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | "Recurso no encontrado." | `null` |
| `MissingBusinessContextException` | 403 | "No hay un negocio asociado a esta petición." | `null` |
| `ThrottleRequestsException` | 429 | "Demasiadas peticiones. Probá de nuevo más tarde." | `null` |
| Resto | 500 | "Ocurrió un error inesperado." | `null` |

En 500, cuando `APP_DEBUG` está activo, Laravel sigue mostrando su traza; el renderer genérico solo se aplica con debug apagado, para no dificultar el diagnóstico en desarrollo.

Las Actions lanzan `ValidationException` para las violaciones de reglas de negocio (horario ocupado, plazo de cancelación vencido, estado inválido), así que esos casos salen como 422 con el campo correspondiente en `errors` — sin código nuevo.

## Resources

En `app/Http/Resources/`:

- `UserResource` — `id`, `name`, `email`, `role`, `business_id`.
- `ServiceResource` — `id`, `name`, `description`, `duration_minutes`, `buffer_minutes`, `price`, `deposit_amount`, `is_active`.
- `EmployeeResource` — `id`, `name`, `email`, `is_active`.
- `SlotResource` — `starts_at`, `ends_at`.
- `BookingResource` — `id`, `status`, `starts_at`, `ends_at`, `price`, `deposit_amount`, `notes`, `source`, más `service`, `employee`, `customer` y `business` (`id`, `name`, `slug`, `timezone`) cuando están cargadas, vía `whenLoaded`.

Las fechas se serializan en ISO-8601 **en la zona horaria del negocio** (`2026-08-20T14:30:00-03:00`), no en UTC: las reservas se guardan en UTC pero el consumidor razona en hora local del negocio, y el offset explícito evita ambigüedad. `BookingResource` obtiene la zona desde `$this->business->timezone`.

`BookingResource` nunca accede a `$this->service` sin precaución: `Service` usa `BelongsToBusiness`, y en una petición de un customer no hay negocio ligado. Los controllers cargan las relaciones con `withoutGlobalScope(BusinessScope::class)` antes de pasar el modelo al Resource, igual que hace hoy `MyBookingsController::index`.

## Paginación y límites de tasa

`GET /api/bookings` pagina de a 15 por defecto. `?per_page` acepta 1–100; fuera de rango, falla la validación. Orden por `starts_at` descendente. Filtros opcionales: `status` (valor del enum `BookingStatus`), `from` y `to` (fechas, comparadas contra `starts_at`), `employee_id` (solo tiene efecto para staff).

Límites definidos en un `ServiceProvider` con `RateLimiter::for(...)`:

- `api`: 60 peticiones por minuto por usuario autenticado, con IP como clave de reserva.
- `api-login`: 5 por minuto por combinación de email + IP, replicando el criterio de `LoginRequest::throttleKey()`.

El grupo de rutas API se registra en `bootstrap/app.php` con `apiPrefix: 'api'` y el middleware `throttle:api`; la ruta de login suma `throttle:api-login`.

## Documentación

`dedoc/scramble` como dependencia de desarrollo. Genera el OpenAPI a partir de las rutas, los Form Requests y los Resources, y publica una UI navegable en `/docs/api`. Se configura para exponer solo el prefijo `api` y se restringe el acceso a entorno local (el gate por defecto de Scramble ya lo hace).

Además, `docs/api.md`: qué es la API, cómo obtener un token, cómo mandarlo (`Authorization: Bearer <token>`), la forma del envelope, la tabla de endpoints y un par de ejemplos `curl` de punta a punta (login → disponibilidad → crear reserva).

## Tests

Todos en `tests/Feature/Api/`, clases PHPUnit con `RefreshDatabase`:

- `AuthTest` — login correcto devuelve token y usuario; credenciales inválidas dan 401; usuario inactivo da 401; staff de negocio inactivo da 401; logout revoca solo el token usado; una ruta protegida sin token da 401 con envelope.
- `ServicesTest` — staff ve solo los servicios de su negocio; la ruta por slug devuelve los del negocio del slug; un slug inexistente da 404.
- `EmployeesTest` — listado y filtro por `service_id`.
- `AvailabilityTest` — los slots coinciden con los que devuelve `AvailabilityService` para los mismos argumentos; parámetros faltantes dan 422.
- `BookingsIndexTest` — staff ve las reservas de su negocio y no las de otro; un customer ve solo las propias, de cualquier negocio; paginación y `per_page` fuera de rango.
- `BookingsWriteTest` — staff crea reserva; cliente crea reserva por slug; un horario ocupado da 422 con `errors.starts_at`; reprogramar por PATCH funciona y valida disponibilidad; cancelar fuera del plazo como cliente da 403; confirmar como customer da 403; confirmar como staff pasa la reserva a `confirmed`.
- `EnvelopeTest` — forma exacta de las cuatro claves en éxito, en 422, en 403 y en 404.
- `RateLimitTest` — superar el límite de login devuelve 429 con envelope.

## Riesgos y decisiones

**Rutas compartidas y scope global.** Es el punto más delicado: `BusinessScope` lanza `MissingBusinessContextException` si no hay negocio ligado fuera de consola. Por eso las rutas compartidas resuelven el scope en el controller y no por middleware, con la lógica concentrada en un solo trait, y hay tests que ejercen ambos roles sobre las mismas rutas.

**Envelope y Resources.** Envolver colecciones de Resources sin perder la forma de cuatro claves obliga a construir el payload a mano en `ApiResponse::paginated` en vez de confiar en el envoltorio automático de Laravel. Es código simple pero hay que aplicarlo de forma consistente; los tests de envelope lo cubren.

**Sanctum y la web.** Instalar Sanctum no debe alterar la autenticación por sesión de Inertia: no se habilita `statefulApi()` y no se toca el guard `web`. La suite existente sirve de red de seguridad.
