# Fase 8 — Gestión de cuenta y negocio (diseño)

Fecha: 2026-08-14
Estado: aprobado en estructura, pendiente de plan de implementación.

## 1. Objetivo

Cerrar las funciones que `01-reservahub.md` §2 promete y que hoy no existen en el backend:

1. Cambio de contraseña con sesión iniciada (hoy solo hay reset por email).
2. Edición del perfil propio (nombre y email).
3. Ajustes del negocio (`name`, `timezone`, `currency`, `cancellation_hours`).
4. Activación y desactivación de usuarios del negocio.
5. Feriados del negocio, integrados en el motor de disponibilidad.

Todo el alcance se expone **por el panel web y por la API REST**. La UI es funcional al nivel del resto del panel; el rediseño visual pertenece a la fase de rediseño frontend, no a esta.

Fuera de alcance, explícitamente: logo/upload (`businesses.logo_path` sigue sin uso), `slug` editable, suspensión del negocio (`businesses.is_active`), cancelación automática de reservas, recurrencia anual de feriados, pagos y tiempo real.

## 2. Punto de partida verificado

Verificado contra el código antes de escribir este documento:

- `BusinessPolicy::update` y `UserPolicy::update` ya existen y **nadie los usa**: no hay ruta de ajustes ni de cambio de estado.
- `is_active` de `users` se respeta en el login web (`LoginRequest`), en el login de API y en `ResolvesBookingScope`, pero no hay forma de cambiarlo.
- No existe tabla de feriados. `AvailabilityService::getAvailableSlots` resta `schedule_breaks`, `time_offs` y bookings; no conoce feriados ni consulta `employee->is_active`.
- El panel vive en `app/Http/Controllers/Dashboard/`; no existe `Controllers/Web/`.
- `SESSION_DRIVER=database`. La tabla `sessions` tiene `user_id` indexado → se pueden invalidar sesiones ajenas por borrado de filas.
- **`Illuminate\Session\Middleware\AuthenticateSession` NO está en el grupo `web`** (`bootstrap/app.php` solo agrega `HandleInertiaRequests`). Consecuencia directa: `Auth::logoutOtherDevices()` **no invalidaría nada** en esta aplicación — se limita a re-hashear la contraseña y actualizar `password_hash_web` en la sesión actual, valor que ninguna otra petición vuelve a comparar. Usarlo daría una falsa sensación de seguridad.
- El login soporta remember-me (`Auth::attempt(..., $this->boolean('remember'))`), así que el `remember_token` es un vector real de re-autenticación.
- `phpunit.xml` fija `SESSION_DRIVER=array`: en tests no se escriben filas en `sessions`.
- Reglas de contraseña reutilizables: `Password::defaults()` (`RegisterRequest`, `ResetPasswordRequest`, `AcceptInvitationRequest`).

## 3. Revocación de acceso: un único mecanismo determinista

Toda la fase usa **una** pieza para cortar accesos, sin duplicación ni `logoutOtherDevices()`.

### `App\Support\UserAccessRevoker`

```php
public function revoke(User $user, ?string $keepSessionId = null): void
```

Pasos, en este orden:

1. **Rotar la autenticación remember-me**: `$user->setRememberToken(Str::random(60))` + `save()`. Invalida toda cookie `remember_web` existente del usuario, incluida la del dispositivo actual. La sesión actual no depende de ella, así que sobrevive.
2. **Revocar todos los tokens de Sanctum**: `$user->tokens()->delete()`.
3. **Borrar las sesiones de base de datos** del usuario: `DB::table(config('session.table'))->where('user_id', $user->id)->when($keepSessionId, fn ($q, $id) => $q->where('id', '!=', $id))->delete()`.

**Falla cerrado.** Antes del paso 1, el revoker verifica `config('session.driver') === 'database'` y, si no lo es, lanza `App\Exceptions\UnsupportedSessionDriverException` sin haber tocado nada. No hay warning ni continuación degradada: con `file`, `redis` o `array` las sesiones web existentes seguirían siendo válidas mientras el llamador cree que revocó todo el acceso, y ese silencio es exactamente el fallo que esta pieza tiene que impedir. `SESSION_DRIVER=database` es un requisito de runtime (§11), no una preferencia.

`$keepSessionId = null` significa "no preserves ninguna sesión".

### Comportamiento por caso

| Caso | `keepSessionId` | Resultado |
|---|---|---|
| Cambio de contraseña web | `$request->session()->getId()` | Sobrevive solo la sesión desde la que se cambió. Además, el controlador llama a `$request->session()->regenerate()` después de revocar, para rotar también el ID actual (anti-fijación). |
| Cambio de contraseña por API | `null` | Caen todas las sesiones web y **todos** los tokens, incluido el token con el que se hizo la llamada. |
| Desactivación de un usuario | `null` | El usuario desactivado pierde sesiones y tokens de inmediato. |

Nunca queda una sesión o token viejo válido: los tres vectores (sesión de base de datos, cookie remember-me, token Sanctum) se cortan en la misma llamada.

### Respuesta documentada del cambio de contraseña por API

`PUT /api/account/password` responde `200` con el envelope estándar:

```json
{
  "success": true,
  "data": null,
  "message": "Contraseña actualizada. Todos los tokens fueron revocados; iniciá sesión de nuevo.",
  "errors": null
}
```

El token usado queda inválido a partir de esa respuesta: la siguiente petición con él devuelve `401` con `"No autenticado."`. Se documenta en `docs/api.md`.

## 4. Área de cuenta (`/account`)

Disponible para **todo usuario autenticado**, incluidos los `customer` sin `business_id`. Fuera del middleware `business`.

### Rutas web (`routes/account.php`, dentro de `middleware('auth')`)

| Método | URI | Nombre | Acción |
|---|---|---|---|
| GET | `/account` | `account.edit` | `Account\ProfileController@edit` |
| PATCH | `/account/profile` | `account.profile.update` | `Account\ProfileController@update` |
| PUT | `/account/password` | `account.password.update` | `Account\PasswordController@update` |

`routes/web.php` agrega el `require`.

### Rutas API (`auth:sanctum`, sin `business`)

| Método | URI | Nombre |
|---|---|---|
| GET | `/api/account` | `api.account.show` |
| PATCH | `/api/account/profile` | `api.account.profile.update` |
| PUT | `/api/account/password` | `api.account.password.update` |

### Piezas

- `App\Actions\Account\UpdateProfile` — actualiza `name` y `email`. Si el email cambia: `email_verified_at = null` y `$user->sendEmailVerificationNotification()`. No toca sesiones ni tokens.
- `App\Actions\Account\ChangePassword` — dentro de una transacción: `forceFill(['password' => ...])` + `save()`, después `UserAccessRevoker::revoke()` con el `keepSessionId` de la tabla de §3.
- `App\Http\Requests\Account\UpdateProfileRequest` — `name` requerido, `email` requerido con `Rule::unique('users','email')->ignore($user->id)`.
- `App\Http\Requests\Account\UpdatePasswordRequest` — `current_password` con la regla `current_password`, `password` con `['required','confirmed', Password::defaults()]`. Mensajes en español.
- `App\Http\Resources\AccountResource` — `id`, `name`, `email`, `email_verified_at`, `role`, `business_id`.
- `resources/js/pages/Account/Edit.jsx` — dos formularios (perfil, contraseña) y aviso de re-verificación cuando cambia el email.

Sin Policy: el usuario opera siempre sobre `$request->user()`. No hay ruta que reciba un `{user}` en el área de cuenta.

## 5. Ajustes del negocio (`/dashboard/settings`)

Autorización: `BusinessPolicy::update` (ya existente, `Role::managers()` + mismo `business_id`).

### Rutas

| Método | URI | Nombre |
|---|---|---|
| GET | `/dashboard/settings` | `dashboard.settings.edit` |
| PUT | `/dashboard/settings` | `dashboard.settings.update` |
| GET | `/api/business` | `api.business.show` |
| PUT | `/api/business` | `api.business.update` |

Las de API van bajo `['auth:sanctum', 'business']`.

### Validación

`App\Http\Requests\Dashboard\UpdateBusinessRequest` (compartido con la API):

- `name`: `required|string|max:255`
- `timezone`: `required|timezone:all`
- `currency`: `['required', Rule::enum(Currency::class)]`
- `cancellation_hours`: `required|integer|min:0|max:168`

`slug`, `logo_path` e `is_active` no se aceptan. El `slug` es la URL pública (`/businesses/{slug}`, `BindPublicBusiness`) y cambiarlo rompería enlaces ya compartidos.

### Estrategia de moneda (ISO-4217)

Se crea `App\Enums\Currency: string` con un conjunto **explícito y acotado** de códigos ISO-4217 relevantes para el proyecto:

```
ARS, USD, EUR, BRL, CLP, COP, MXN, PEN, UYU
```

Razones de esta estrategia, documentadas aquí porque la spec la promete:

- Validar solo "tres letras" aceptaría `XXX` o `ABC` y dejaría el campo sin significado.
- Traer una dependencia con la tabla ISO-4217 completa (`alcohol/iso4217`, `moneyphp/money`) agrega peso de mantenimiento para un catálogo que este SaaS no necesita: ReservaHub es un proyecto de demo regional, no un procesador multi-divisa.
- Un enum del dominio se testea, se autocompleta, alimenta el `<select>` del formulario y admite agregar códigos con una línea.

La columna `businesses.currency` **no** se castea al enum en el modelo: hay datos y tests previos que la tratan como string, y castear ampliaría el alcance de la fase sin beneficio. El enum se usa para validar y para poblar el formulario.

### Cambio de zona horaria

`bookings.starts_at`/`ends_at` están en UTC: cambiar `timezone` **no mueve ningún instante ya persistido**, solo cambia cómo se presenta. `schedules.start_time`/`end_time` guardan hora local (`'09:00'`), así que pasan a significar esa hora en la zona nueva — es lo que un dueño espera al mudar el negocio de zona. No hay migración de datos ni conversión de horarios.

La pantalla muestra un aviso explícito antes de guardar cuando el campo `timezone` cambió. Test obligatorio: una reserva persistida antes del cambio conserva `starts_at` idéntico en UTC después del cambio.

## 6. Activación y desactivación de usuarios

### Rutas

| Método | URI | Nombre |
|---|---|---|
| PUT | `/dashboard/users/{user}/status` | `dashboard.users.status.update` |
| PUT | `/api/users/{user}/status` | `api.users.status.update` |

Cuerpo: `is_active` booleano requerido (`App\Http\Requests\Dashboard\UpdateUserStatusRequest`). Web bajo `['auth','business']`, API bajo `['auth:sanctum','business']`.

### Autorización — jerarquía explícita

`UserPolicy` gana `setActiveStatus(User $actor, User $target): bool`. Reglas, todas en la Policy porque dependen solo de identidad y rol:

1. `$actor->business_id !== null` y `$actor->business_id === $target->business_id` — cross-business siempre denegado.
2. `$actor->id !== $target->id` — nadie cambia su propio estado.
3. Matriz de roles:

| Actor \ Target | `owner` | `admin` | `employee` |
|---|---|---|---|
| `owner` | permitido | permitido | permitido |
| `admin` | **denegado** | permitido | permitido |
| `employee` | denegado | denegado | denegado |

Un `admin` nunca activa ni desactiva a un `owner`. Un `owner` puede sobre otro `owner`, sujeto al invariante de §6.2.

Los `customer` quedan fuera por construcción, no por una regla extra: `RegisterCustomer` y `UserFactory` los crean siempre con `business_id = null`, así que la regla 1 ya los excluye. Ningún manager administra el estado de un cliente en esta fase.

`UserPolicy::update`/`delete` (existentes) quedan como están: son otra pregunta y otros consumidores.

### 6.2 Invariante del último owner activo

Vive en la Action, **no** en la Policy: depende del estado actual de los datos, no de la identidad del actor.

`App\Actions\Users\SetUserActiveStatus` rechaza con `ValidationException` en español (`No podés desactivar al último propietario activo del negocio.`) cuando se intenta desactivar a un `owner` y no queda otro `owner` con `is_active = true` en el mismo `business_id`. La consulta de conteo y el update corren dentro de una transacción con `lockForUpdate()` sobre los owners del negocio, para que dos desactivaciones simultáneas no dejen el negocio sin propietario.

### Efectos

Al desactivar: `UserAccessRevoker::revoke($target, null)` — sesiones, remember-me y tokens caen de inmediato.

Al desactivar un empleado con reservas futuras: **no se cancela nada**. La Action devuelve el conteo de reservas `pending|confirmed` con `starts_at > now()` del empleado, y la respuesta lo informa (flash en web, `data.future_bookings_count` en API) para que el manager decida reasignar o cancelar.

Al reactivar: solo cambia `is_active`. No se restauran sesiones ni tokens.

### UI

`resources/js/pages/Dashboard/Employees/Index.jsx` gana el toggle por fila (ya recibe `is_active`), con confirmación y el aviso de reservas futuras.

## 7. Feriados del negocio

### Tabla `business_holidays`

```php
Schema::create('business_holidays', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->date('starts_on');
    $table->date('ends_on');
    $table->timestamps();

    $table->index(['business_id', 'starts_on', 'ends_on']);
});
```

Rango inclusivo de días completos: cubre un feriado suelto y un cierre de varios días con una sola fila. Sin recurrencia anual (se cargan a mano cada año) y sin feriados parciales (eso ya lo cubre `time_offs` a nivel empleado).

`App\Models\BusinessHoliday` usa `App\Models\Concerns\BelongsToBusiness` (global scope `BusinessScope`), castea `starts_on`/`ends_on` a `date`.

### Rutas

| Método | URI | Nombre |
|---|---|---|
| GET | `/dashboard/holidays` | `dashboard.holidays.index` |
| POST | `/dashboard/holidays` | `dashboard.holidays.store` |
| DELETE | `/dashboard/holidays/{holiday}` | `dashboard.holidays.destroy` |
| GET | `/api/holidays` | `api.holidays.index` |
| POST | `/api/holidays` | `api.holidays.store` |
| DELETE | `/api/holidays/{holiday}` | `api.holidays.destroy` |

Sin `update`: borrar y recrear evita reabrir la validación de conflictos sobre un rango mutante.

### Autorización y resolución cross-tenant

`BusinessHolidayPolicy`, con dos semánticas distintas:

- `viewAny(User $actor)` y `create(User $actor)` — no hay recurso todavía: autorizan contra el **negocio actual** del actor (`$actor->business_id !== null` y `in_array($actor->role, Role::managers(), true)`). El negocio ya viene fijado por el middleware `business`.
- `delete(User $actor, BusinessHoliday $holiday)` — lo anterior **más** pertenencia del recurso: `$actor->business_id === $holiday->business_id`.

Sobre el cross-tenant: `BelongsToBusiness` agrega el global scope `BusinessScope`, que filtra por `business_id` del negocio ligado en el contenedor. El binding implícito de `{holiday}` corre sobre ese query scopeado, así que un feriado de otro negocio **no se resuelve**: lanza `ModelNotFoundException` y la aplicación devuelve **404** (en API, el envelope `"Recurso no encontrado."` que ya mapea `bootstrap/app.php`). Se documenta y se testea como 404; el scope no se desactiva para forzar un 403 — filtrar antes de autorizar es la propiedad de tenancy que el resto del proyecto ya sostiene, y un 404 tampoco confirma la existencia del recurso ajeno.

La comprobación de pertenencia en `delete` queda igual, como defensa en profundidad para cualquier llamador que resuelva el modelo sin el scope (consola, jobs, tests).

El **403** se reserva para un recurso del mismo negocio que sí se resolvió y un actor sin permiso — típicamente un `employee`.

### 7.1 Detección de conflictos con reservas

`App\Actions\Holidays\CreateBusinessHoliday` valida, en orden:

1. `ends_on >= starts_on` (regla `after_or_equal` en el Form Request).
2. No solapa con otro feriado del mismo negocio (`starts_on <= other.ends_on AND ends_on >= other.starts_on`).
3. **No solapa con reservas activas**, con solapamiento de intervalos — no "el inicio cae dentro".

El rango local inclusivo se convierte a un intervalo UTC semiabierto usando `business->timezone`:

```php
$holidayStartUtc = CarbonImmutable::parse($startsOn, $tz)->startOfDay()->utc();
$holidayEndUtc   = CarbonImmutable::parse($endsOn, $tz)->addDay()->startOfDay()->utc();
// intervalo [$holidayStartUtc, $holidayEndUtc)
```

Se rechaza cuando existe una reserva con `status` en `pending|confirmed` que cumpla:

```sql
bookings.starts_at <  :holiday_end_utc
AND bookings.ends_at  >  :holiday_start_utc
```

Así también se detecta la reserva que empieza antes del límite del feriado y continúa dentro de él, que un chequeo sobre `starts_at` solo se perdería. La consulta va scopeada por `business_id` (además del global scope).

### 7.2 Respuesta de conflicto

El mensaje **no** enumera todas las reservas afectadas. Se devuelve una `ValidationException` sobre el campo `starts_on` con:

- El total afectado: `No podés crear el feriado: hay N reservas activas en ese rango. Cancelalas o reprogramalas primero.`
- Una vista previa acotada a **las 5 primeras** por `starts_at`, en `errors` bajo la clave `bookings_preview`.

`ValidationException::withMessages()` solo transporta arrays de strings, así que la vista previa es un array de líneas ya formateadas en la zona del negocio — `"12/09 10:00 — Corte de pelo — Ana Gómez"` — y no objetos. Sale de la consulta ya scopeada por `business_id`, así que no puede exponer datos de otro tenant, y no incluye nombre ni contacto del cliente. La UI muestra el total y la vista previa, y nada más. **No hay enlace a un listado de reservas filtrado por fecha**: verificado que `Dashboard\BookingController@index` no acepta ningún filtro (`Booking::with(...)->orderByDesc('starts_at')->get()`) y que `from`/`to`/`employee_id` existen solo en `Api\BookingIndexRequest`. Ese enlace exigiría filtros nuevos en el listado del panel — una función de frontend que esta fase no tiene por qué arrastrar de contrabando. Queda para la fase de rediseño frontend, que es la dueña del listado de reservas.

### 7.3 Borrado

`DELETE` no valida nada: quitar un feriado solo libera disponibilidad.

### UI

`resources/js/pages/Dashboard/Holidays/Index.jsx` — listado ordenado por `starts_on` y formulario de alta (nombre, desde, hasta).

## 8. Integración en `AvailabilityService`

Dos guardas nuevas al principio de `getAvailableSlots`, **antes** de buscar el `Schedule`, después de la validación de pertenencia al negocio que ya existe:

1. `if (! $employee->is_active) { return []; }` — un ID de empleado desactivado pasado a mano deja de devolver slots.
2. Feriado del negocio que cubra el día local consultado:

```php
$holiday = BusinessHoliday::query()
    ->where('business_id', $business->id)
    ->where('starts_on', '<=', $localDate->toDateString())
    ->where('ends_on', '>=', $localDate->toDateString())
    ->exists();

if ($holiday) { return []; }
```

`$localDate` ya está calculado en la zona del negocio por el código actual. Nada más del motor cambia: pausas, licencias, bookings y buffers siguen igual.

Efecto colateral deseado y documentado: las respuestas de `/api/availability` y `/api/businesses/{slug}/availability` reflejan feriados y empleados inactivos, porque comparten el motor.

## 9. Layout de archivos

```
app/
├── Actions/
│   ├── Account/{UpdateProfile,ChangePassword}.php
│   ├── Holidays/{CreateBusinessHoliday,DeleteBusinessHoliday}.php
│   ├── Businesses/UpdateBusinessSettings.php
│   └── Users/SetUserActiveStatus.php
├── Enums/Currency.php
├── Exceptions/UnsupportedSessionDriverException.php
├── Http/
│   ├── Controllers/
│   │   ├── Account/{ProfileController,PasswordController}.php
│   │   ├── Api/{AccountController,BusinessController,UserStatusController,HolidayController}.php
│   │   └── Dashboard/{BusinessSettingsController,UserStatusController,HolidayController}.php
│   ├── Requests/
│   │   ├── Account/{UpdateProfileRequest,UpdatePasswordRequest}.php
│   │   └── Dashboard/{UpdateBusinessRequest,UpdateUserStatusRequest,StoreHolidayRequest}.php
│   └── Resources/{AccountResource,BusinessResource,HolidayResource}.php
├── Models/BusinessHoliday.php
├── Policies/BusinessHolidayPolicy.php
└── Support/UserAccessRevoker.php

database/migrations/2026_08_14_000001_create_business_holidays_table.php
database/factories/BusinessHolidayFactory.php
routes/account.php
resources/js/pages/Account/Edit.jsx
resources/js/pages/Dashboard/Settings/Edit.jsx
resources/js/pages/Dashboard/Holidays/Index.jsx
```

Los controladores de API y de panel comparten Actions y Form Requests; solo difieren en la respuesta (Inertia vs `ApiResponse`).

## 10. Tests

**Unit**

- `tests/Unit/Services/AvailabilityServiceTest` — empleado inactivo devuelve `[]`; día dentro de un feriado devuelve `[]`; día contiguo al feriado sigue devolviendo slots; feriado de otro negocio no afecta.
**Feature — web**

- `tests/Feature/Account/UserAccessRevokerTest` — vive en Feature porque toca base de datos. Dos escenarios, sin zona gris:
  - **driver `database`** (`config()->set('session.driver','database')`, porque `phpunit.xml` fija `array`) con filas insertadas a mano en `sessions`: revocación completa — borra las sesiones del usuario, preserva la de `keepSessionId`, no toca las de otro usuario, rota `remember_token`, borra todos los tokens.
  - **driver no-`database`** (`array`): lanza `UnsupportedSessionDriverException` y **no** modifica `remember_token`, tokens ni filas de sesión. Ningún test afirma revocación completa bajo un driver no soportado.

- `Account/ProfileTest` — actualiza nombre; cambiar email limpia `email_verified_at` y envía la notificación; email duplicado falla; un `customer` sin negocio accede a `/account`.
- `Account/PasswordTest` — `current_password` incorrecta falla; al cambiarla la sesión actual sobrevive, las demás filas de `sessions` del usuario se borran, `remember_token` cambia y los tokens caen.
- `Dashboard/BusinessSettingsTest` — `owner`/`admin` editan; `employee` recibe 403; cross-business 403; `slug` enviado se ignora; moneda fuera del enum falla; cambiar `timezone` no mueve el `starts_at` UTC de una reserva existente.
- `Dashboard/UserStatusTest` — matriz de roles de §6; auto-desactivación denegada; último owner activo rechazado con mensaje; desactivar corta sesiones y tokens; reporta el conteo de reservas futuras sin cancelarlas; reactivar funciona.
- `Dashboard/UserStatusConcurrencyTest` — invariante de §6.2 bajo concurrencia real. El proyecto ya tiene infraestructura para esto: `tests/Unit/Database/AdvisoryLockTest.php` abre dos sesiones PDO crudas contra Postgres, así que el requisito es ejecutable y no queda como aspiración. Escenario: un negocio con **dos** owners activos y dos desactivaciones concurrentes, una por cada owner. Invariante final, sin importar cuál gane: `owners activos >= 1`. Una de las dos operaciones tiene que fallar con la `ValidationException` del último owner. El plan de implementación elige el mecanismo concreto (dos conexiones PDO al estilo `AdvisoryLockTest`, o un proceso auxiliar); el requisito es de esta spec.
- `Dashboard/HolidaysTest` — alta/listado/borrado; solapamiento con otro feriado rechazado; reserva que **empieza antes** del feriado y termina dentro lo bloquea; reserva `cancelled` no bloquea; la vista previa se corta en 5 y trae el total; `employee` recibe **403**; un feriado de otro negocio recibe **404** (el global scope impide resolverlo), no 403.

**Feature — API**

- `Api/AccountTest` — `GET /api/account`; cambio de contraseña revoca el token usado (la siguiente petición da 401) y devuelve el mensaje documentado.
- `Api/BusinessTest` — show/update con envelope; `employee` 403.
- `Api/UsersTest` — cambio de estado con envelope y `data.future_bookings_count`; jerarquía admin→owner denegada.
- `Api/HolidaysTest` — index/store/destroy con envelope; conflicto devuelve 422 con `errors.starts_on` y `errors.bookings_preview`; borrar un feriado de otro negocio devuelve 404 con `"Recurso no encontrado."`.
- `Api/AvailabilityTest` — un feriado quita el día de la disponibilidad pública.

## 11. Documentación a actualizar

- `docs/api.md` + anotaciones de Scramble: nuevos endpoints de cuenta, negocio, estado de usuario y feriados; nota explícita de que cambiar la contraseña por API invalida el token usado.
- `01-reservahub.md` §7: fila de la Fase 8 pasa a "Hecha" con evidencia; §3 suma `business_holidays` al modelo de datos.
- `CLAUDE.md`: sección corta sobre `UserAccessRevoker` como único mecanismo de revocación y sobre el enum `Currency`.
- `docs/DEPLOYMENT_HANDOFF.md`: **el contrato de runtime se endurece**. No hay servicio, variable de entorno ni ruta persistente nueva, pero `SESSION_DRIVER=database` deja de ser una conveniencia y pasa a ser un **requisito operativo explícito**: con cualquier otro driver, `UserAccessRevoker` lanza `UnsupportedSessionDriverException` y el cambio de contraseña y la desactivación de usuarios fallan en producción con error 500. Se documenta como tal, junto con la tabla `sessions` que la aplicación necesita presente y migrada.

## 12. Riesgos y decisiones asumidas

- **Driver de sesión**: la invalidación de sesiones ajenas depende de `SESSION_DRIVER=database`, y `UserAccessRevoker` falla cerrado con `UnsupportedSessionDriverException` si no lo es. Riesgo asumido: un despliegue mal configurado rompe el cambio de contraseña y la desactivación de usuarios con un 500 en vez de degradarse. Es la contrapartida buscada — un fallo ruidoso es preferible a creer que se revocó el acceso y no haberlo hecho.
- **Sin `AuthenticateSession`**: no se agrega ese middleware en esta fase. Agregarlo cambiaría el comportamiento de cierre de sesión de toda la aplicación y merece su propia decisión.
- **Feriados y reservas ya creadas**: el alta se bloquea en vez de cancelar. Puede resultar molesto en un negocio con agenda cargada; es la contrapartida aceptada de no cancelar nunca en silencio.
- **Zona horaria**: no se desplazan los `schedules`. Un dueño que cambie de zona esperando conservar el instante absoluto de su jornada tendrá que reeditarlos.
