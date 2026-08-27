# ReservaHub

Sistema SaaS de reservas para comercios y profesionales. Puede servir para peluquerías, gimnasios, talleres, profesores, estudios o cualquier negocio que trabaje con turnos.

## 1. Objetivo del proyecto

Demostrar que podés construir una aplicación Laravel completa con:

- MVC.
- Autenticación.
- Roles y permisos.
- Multi-tenancy simple.
- Reglas de disponibilidad.
- Prevención de turnos superpuestos.
- API REST.
- Pagos y webhooks.
- Colas.
- Notificaciones.
- Tareas programadas.
- Tiempo real.
- Tests.
- Docker y CI/CD.

## 2. Alcance funcional

### Empresas

- Registro de una empresa.
- Datos comerciales.
- Zona horaria.
- Moneda.
- Configuración de cancelación.
- Estado activo o suspendido.

Logo: fijo, el mismo para todos los negocios (asset del frontend). Sin upload; `businesses.logo_path` queda sin uso a propósito.

### Usuarios

Roles sugeridos:

- `owner`: propietario de la empresa.
- `admin`: administra configuración y empleados.
- `employee`: ve y administra sus reservas.
- `customer`: reserva servicios y ve su historial.

Funciones:

- Registro.
- Inicio y cierre de sesión.
- Recuperación de contraseña.
- Verificación de correo.
- Cambio de contraseña.
- Invitación de empleados.
- Activación y desactivación de usuarios.

### Servicios

- Nombre.
- Descripción.
- Duración en minutos.
- Precio.
- Seña requerida.
- Tiempo de preparación entre turnos.
- Estado activo.
- Empleados habilitados.

### Disponibilidad

- Horarios semanales por empleado.
- Pausas (`schedule_breaks`).
- Licencias por empleado (`time_offs`).
- Feriados del negocio.
- Bloqueos manuales (se cubren con licencias).
- Duración configurable por servicio.
- Zona horaria de la empresa.

### Reservas

- Crear turno.
- Confirmar turno.
- Reprogramar.
- Cancelar.
- Completar.
- Marcar ausencia.
- Agregar notas internas.
- Registrar origen: web, administración o API.
- Evitar solapamientos.

Estados:

```text
pending
confirmed
cancelled
completed
no_show
```

### Notificaciones

- Confirmación de reserva.
- Reprogramación.
- Cancelación.
- Recordatorio 24 horas antes.
- Recordatorio 2 horas antes.
- Aviso al empleado.

Canales:

- Email.
- Base de datos.

WhatsApp queda **fuera del alcance de este proyecto** (ni real ni simulado).

### Pagos

- Crear una intención de pago o preferencia.
- Asociar el pago a una reserva.
- Procesar webhook.
- Evitar webhooks duplicados.
- Registrar payload recibido.
- Confirmar reserva al aprobarse el pago.
- Marcar pago rechazado o pendiente.
- Simular el proveedor durante tests.

### Dashboard

- Reservas de hoy.
- Próximos turnos.
- Cancelaciones.
- Ingresos estimados.
- Servicios más solicitados.
- Empleados con más reservas.

Implementado en la **Fase 11** (§7): `Pages/Dashboard/Index.jsx` muestra turnos de hoy (con desglose por estado), reservas esperando seña, reservas por vencer en los próximos 15 minutos, confirmadas de los próximos 7 días, el riel de la jornada y una cola de "requieren atención" — todo servido por `App\Http\Controllers\DashboardController` con consultas reales de Eloquent, sin ninguna cifra hardcodeada en el frontend.

## 3. Modelo de datos

### Tablas principales

#### businesses

```text
id
name
slug
timezone
currency
cancellation_hours
logo_path
is_active
created_at
updated_at
```

#### users

```text
id
business_id nullable
name
email
password
email_verified_at
is_active
created_at
updated_at
```

#### roles y permisos

Podés usar tablas propias o un paquete conocido. Para aprender, conviene comenzar con una columna `role` y después migrar a permisos más granulares.

#### services

```text
id
business_id
name
description
duration_minutes
buffer_minutes
price
deposit_amount
is_active
created_at
updated_at
```

#### employee_service

```text
employee_id
service_id
```

#### schedules

```text
id
employee_id
day_of_week
start_time
end_time
is_active
```

#### schedule_breaks

```text
id
schedule_id
start_time
end_time
```

#### time_offs

```text
id
employee_id
starts_at
ends_at
reason
```

#### business_holidays

```text
id
business_id
name
starts_on
ends_on
created_at
updated_at
```

#### bookings

```text
id
business_id
customer_id
employee_id
service_id
starts_at
ends_at
status
price
deposit_amount
notes
source
cancelled_at
created_at
updated_at
```

#### payments

```text
id
booking_id
provider
external_id
status
amount
currency
paid_at
raw_response json
created_at
updated_at
```

#### webhook_events

```text
id
provider
external_event_id
payload json
processed_at
status
error_message
created_at
updated_at
```

### Restricciones e índices

- Índice por `business_id` en todas las tablas multi-tenant.
- Índice compuesto para reservas por `employee_id`, `starts_at`, `ends_at`.
- `external_event_id` único por proveedor.
- Índice por estado y fecha de reserva.
- Foreign keys con políticas de borrado explícitas.

## 4. Arquitectura sugerida

```text
app/
├── Actions/
│   └── Bookings/
│       ├── CreateBooking.php
│       ├── CancelBooking.php
│       ├── ConfirmBooking.php
│       └── RescheduleBooking.php
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   │   ├── Web/
│   │   └── Api/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Listeners/
├── Models/
├── Notifications/
├── Policies/
├── Services/
│   ├── AvailabilityService.php
│   └── Payments/
└── Support/
```

### Responsabilidades

- Controller: coordina request y response.
- Form Request: valida entrada.
- Policy: autoriza.
- Action: ejecuta caso de uso.
- Service: lógica reutilizable o integración.
- Model: relaciones, casts y scopes.
- Job: ejecución asincrónica.
- Event/Listener: efectos secundarios.
- Resource: salida JSON.

## 5. Endpoints API

```text
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/services
GET    /api/employees
GET    /api/availability
POST   /api/bookings
GET    /api/bookings
GET    /api/bookings/{booking}
PATCH  /api/bookings/{booking}
POST   /api/bookings/{booking}/cancel
POST   /api/bookings/{booking}/confirm
POST   /api/bookings/{booking}/payments
GET    /api/bookings/{booking}/payments
GET    /api/bookings/{booking}/payments/{payment}
POST   /api/webhooks/payments/{provider}
```

El pago cuelga de la reserva en vez de vivir en `/api/payments`: la reserva es
contexto obligatorio y anidar reutiliza la resolución de scope de reservas.

### Respuesta estándar

```json
{
  "success": true,
  "data": {},
  "message": "Reserva creada correctamente.",
  "errors": null
}
```

## 6. Reglas de negocio importantes

- Un empleado no puede tener dos reservas superpuestas.
- La duración sale del servicio y no del cliente.
- Una reserva debe estar dentro del horario laboral.
- Debe respetar pausas, feriados y licencias.
- Un cliente no puede cancelar fuera del plazo permitido.
- Un webhook repetido no puede duplicar un pago.
- Una reserva con seña queda pendiente hasta confirmar el pago.
- Una reserva con seña tiene una ventana de pago; vencida y sin pago resuelto, se cancela automáticamente y libera el turno.
- Toda consulta debe filtrar por empresa.

## 7. Implementación por fases

### Estado actual (verificado contra el código y los tests)

| Fase | Estado | Evidencia |
|---|---|---|
| 0 — Preparación | Hecha | Proyecto Laravel 13 + Sail, `.env.example`, Pint, pnpm, `.github/workflows/ci.yml` (cerrado en la Fase 12) |
| 1 — Autenticación | Hecha | `tests/Feature/Auth/*` |
| 2 — Empresas y tenancy | Hecha | `tests/Feature/Tenancy/*`, `tests/Feature/Policies/*`, `EnsureBusinessContext` |
| 3 — Servicios y empleados | Hecha | `tests/Feature/Dashboard/*`, `DemoSeeder` |
| 4 — Motor de disponibilidad | Hecha | `app/Services/AvailabilityService.php`, `tests/Unit/Services/AvailabilityServiceTest.php` |
| 5 — Reservas | Hecha | `app/Actions/Bookings/*`, `tests/Feature/Bookings/*` (incluye concurrencia) |
| 6 — Notificaciones y scheduler | Hecha | `app/Notifications/Bookings/*`, `SendBookingReminders`, contenedores `queue` y `scheduler` |
| 7 — API y Sanctum | Hecha | `routes/api.php`, `tests/Feature/Api/*`, `docs/api.md` + OpenAPI |
| 8 — Gestión de cuenta y negocio | Hecha | `tests/Feature/Account/*`, `tests/Feature/Dashboard/{BusinessSettingsTest,UserStatusTest,UserStatusConcurrencyTest,HolidaysTest}`, `tests/Feature/Api/{AccountTest,BusinessTest,UsersTest,HolidaysTest}`, `business_holidays` en `AvailabilityService` |
| 9 — Pagos | Hecha | `app/Services/Payments/*`, `app/Actions/Payments/*`, `payments`/`webhook_events`, `tests/Feature/Payments/*` (incluye concurrencia), `payments:reconcile` y `bookings:expire-unpaid` en el scheduler |
| 10 — Tiempo real | Hecha | `laravel/reverb`, `app/Events/Broadcasting/BookingChanged.php`, `app/Listeners/BroadcastBookingChange.php`, `routes/channels.php`, servicio `reverb` en `compose.yaml`, `tests/Feature/Realtime/*` |
| 10.5 — Listado público de negocios | Hecha | `Public\BusinessController::index()` + `GET /negocios`, `Api\BusinessController::index()` + `GET /api/businesses`, `Pages/Public/Business/Index.jsx`, link desde `Home.jsx`, `tests/Feature/Public/BusinessIndexTest`, `tests/Feature/Api/BusinessesIndexTest`, `DemoSeeder` con dos negocios |
| 11 — Rediseño y experiencia frontend | Hecha | Rediseño completo de landing/pública/dashboard, sistema de diseño propio, `DemoSeeder` expandido a clientes y reservas (23 reservas, cuatro estados estables, tres pagos de seña aprobados — cerrado por esta fase, ya no queda pendiente de la Fase 12), buzón público de Mailpit documentado y enlazado (`VITE_DEMO_MAIL_URL`), aviso de demo y guía de uso, `docs/DEPLOYMENT_HANDOFF.md` actualizado al modelo de demo pública |
| 12 — Release readiness y handoff | En curso | `docs/DEPLOYMENT_HANDOFF.md` reescrito para el modelo VPS multiproyecto, `docs/RELEASE.md`, `README.md` propio, `.github/workflows/{ci,release}.yml`, `docker/production/{app,web}.Dockerfile`, `compose.production.yaml`, `php artisan demo:reset` y `demo:restore-access` con las guardas de `DemoEnvironment`, `trustProxies` configurado. Pendientes: publicación del repositorio en GitHub (Tarea 21) y el release `v1.0.0` (Tarea 22) |

### Fase 0 — Preparación

1. Crear repositorio.
2. Crear proyecto Laravel.
3. Configurar `.env.example`.
4. Configurar base de datos.
5. Instalar frontend.
6. Configurar formatter.
7. Crear pipeline inicial.

Comandos orientativos:

```bash
composer create-project laravel/laravel reservahub
cd reservahub
php artisan key:generate
php artisan migrate
npm install
npm run build
```

### Fase 1 — Autenticación

1. Instalar starter kit.
2. Registro e inicio de sesión.
3. Verificación de email.
4. Recuperación de contraseña.
5. Tests de autenticación.

### Fase 2 — Empresas y tenancy

1. Crear `businesses`.
2. Asociar usuarios.
3. Middleware para empresa actual.
4. Scope global o consultas explícitas.
5. Policies para evitar acceso cruzado.
6. Tests de aislamiento.

### Fase 3 — Servicios y empleados

1. CRUD de servicios.
2. Asociación empleado-servicio.
3. CRUD de horarios.
4. Pausas y licencias.
5. Seeders de demostración.

### Fase 4 — Motor de disponibilidad

1. Recibir fecha, servicio y empleado.
2. Calcular duración y buffer.
3. Obtener horario laboral.
4. Excluir pausas.
5. Excluir licencias.
6. Excluir reservas existentes.
7. Devolver slots disponibles.
8. Crear tests unitarios.

### Fase 5 — Reservas

1. Crear Form Requests.
2. Crear `CreateBooking` Action.
3. Usar transacción.
4. Volver a validar disponibilidad dentro de la transacción.
5. Crear reserva.
6. Disparar evento.
7. Crear historial.

### Fase 6 — Notificaciones y scheduler

1. Crear notificaciones.
2. Encolar emails.
3. Crear comando para recordatorios.
4. Configurar scheduler.
5. Evitar recordatorios duplicados.
6. Probar con `Notification::fake()`.

### Fase 7 — API y Sanctum

1. Instalar Sanctum.
2. Crear endpoints.
3. Crear Resources.
4. Agregar paginación.
5. Agregar rate limiting.
6. Documentar con Postman u OpenAPI.

### Fase 8 — Gestión de cuenta y negocio

Cerró las funciones que §2 prometía y que hasta la Fase 7 no existían en el backend: cambio de contraseña con sesión iniciada, edición de los ajustes del negocio, activación/desactivación de usuarios y feriados a nivel negocio — cada una con su ruta web (`routes/account.php`, `routes/dashboard.php`) y su espejo en la API (`routes/api.php`), Form Request, Policy y Action, con tests unitarios y de feature.

1. **Cambio de contraseña con sesión iniciada.** `PUT /account/password` (web) y `PUT /api/account/password` (API) exigen la contraseña actual (verificada a mano contra el hash, sin la regla `current_password`, porque el mismo Form Request se reutiliza bajo el guard `sanctum`) y reutilizan las reglas de validación de contraseña del registro. `App\Actions\Account\ChangePassword` revoca el resto del acceso del usuario a través de `App\Support\UserAccessRevoker`: rota el `remember_token`, borra sus tokens de Sanctum y elimina sus demás sesiones de la tabla `sessions` (driver `database`), preservando solo la sesión web que hizo el cambio (`null` desde la API, así que ahí cae también el token que hizo la llamada).
2. **Ajustes del negocio.** `App\Http\Controllers\Dashboard\BusinessSettingsController` (`GET`/`PUT /dashboard/settings`) y su equivalente en la API (`App\Http\Controllers\Api\BusinessController`, `GET`/`PUT /api/business`) permiten editar nombre, zona horaria, moneda y `cancellation_hours`, autorizado por la Policy `update` de `Business` (solo `owner`/`admin`). `App\Actions\Businesses\UpdateBusinessSettings` asigna esos cuatro campos explícitamente (no un `update($data)` masivo) para no exponer `slug`, `logo_path` ni `is_active`.
3. **Activación y desactivación de usuarios.** `PUT /dashboard/users/{user}/status` y `PUT /api/users/{user}/status` llaman a `App\Actions\Users\SetUserActiveStatus`, autorizado por `UserPolicy::setActiveStatus`: exige mismo negocio, prohíbe que alguien se desactive a sí mismo, y prohíbe que un `admin` cambie el estado de un `owner` (un `owner` sí puede cambiar el estado de cualquier otro usuario de su negocio, incluidos otros `owner`). El invariante del último `owner` activo vive en la Action, no en la Policy, porque depende del estado actual de los datos: bloquea (`lockForUpdate`, orden fijo por `id` para evitar deadlock entre desactivaciones simultáneas) las filas de owners activos del negocio y rechaza la operación si el objetivo es el único que queda. Al desactivar, cuenta las reservas futuras (`pending`/`confirmed`) del empleado y revoca su acceso con el mismo `UserAccessRevoker` que usa el cambio de contraseña. En esta fase, el panel web solo expone el toggle en el listado de empleados; activar o desactivar a un `admin` o `owner` es, por ahora, solo vía API (`PUT /api/users/{user}/status`), pendiente de una actualización del panel en una fase posterior.
4. **Feriados del negocio.** La tabla `business_holidays` (`business_id`, `name`, `starts_on`, `ends_on`) se integró en `AvailabilityService` junto con pausas y licencias: un día marcado feriado no ofrece slots. `App\Actions\Holidays\CreateBusinessHoliday` rechaza feriados que se superponen con otro feriado existente o con reservas `pending`/`confirmed` ya persistidas en ese rango (con una vista previa de hasta 5 reservas en conflicto en el mensaje de validación) — así nunca cancela reservas en silencio; hay que cancelarlas o reprogramarlas antes de crear el feriado. Rutas web (`GET`/`POST /dashboard/holidays`, `DELETE /dashboard/holidays/{holiday}`) y API (`GET`/`POST /api/holidays`, `DELETE /api/holidays/{holiday}`); no hay edición, solo alta y baja.

**Logo: fuera de alcance.** El logo es fijo y el mismo para todos los negocios (asset del frontend). No hay upload, `businesses.logo_path` queda sin uso a propósito, y la aplicación sigue sin datos de usuario en disco — el contrato de despliegue no gana storage persistente.

### Fase 9 — Pagos

Cerró el punto 9 del roadmap con un único proveedor **simulado**
(`App\Services\Payments\Simulated\SimulatedPaymentGateway`), ligado al
contrato `PaymentGateway` en `AppServiceProvider`. No hay variable de entorno
para elegir proveedor: un adapter real reemplazaría ese binding sin tocar el
resto del dominio.

1. **Contrato `PaymentGateway`.** `app/Services/Payments/Contracts/PaymentGateway.php` es provider-neutral: ningún tipo de Laravel ni de SDK externo cruza la interfaz. El proveedor simulado guarda su propio estado en `simulated_provider_payments`, deliberadamente **independiente** de `payments`, para que la reconciliación compare dos almacenes de verdad distintos en vez de leerse a sí misma.
2. **Ventana de pago.** La expiración pertenece a la reserva (`bookings.payment_expires_at`), no al pago, con duración `config('payments.window_minutes')` (`PAYMENTS_WINDOW_MINUTES`, 30 minutos por defecto). `bookings:expire-unpaid` cancela vía `CancelBooking` con `CancellationReason::PaymentWindowExpired` (actor nulo, sin el corte de `cancellation_hours`) y nunca cancela mientras haya un intento `pending` sin resolver.
3. **Webhook e idempotencia.** `webhook_events` identifica cada evento por `unique (provider, external_event_id)`; el procesamiento reclama el evento con estado (`received`/`processed`/`ignored`/`failed`) bajo `for update` y aplica el efecto junto con la marca de completado en la misma transacción. `received` y `failed` son reprocesables a propósito, porque un fallo transitorio no puede dejar un evento imposible de procesar. `App\Services\Payments\ProcessPaymentWebhook` es el único borde de procesamiento, usado tanto por el endpoint HTTP como por la entrega en proceso del proveedor simulado.
4. **Aplicación del resultado.** `App\Actions\Payments\ApplyPaymentResult` es el único camino que aplica un resultado del proveedor sobre un pago, y `ConfirmBooking` el único que confirma una reserva (`ConfirmationReason::PaymentApproved`); ni el webhook ni `payments:reconcile` mutan `Booking` por su cuenta.
5. **Reconciliación.** El comando `payments:reconcile` (`PAYMENTS_RECONCILE_BATCH`, `PAYMENTS_RECONCILE_CADENCE_MINUTES`) consulta al proveedor los pagos `pending` que no se reconciliaron dentro de la cadencia configurada y aplica el resultado por el mismo camino que el webhook (`ApplyPaymentResult`), sin tocar `webhook_events`: repara divergencias cuando la entrega del evento se perdió, no reemplaza al webhook.
6. **Checkout simulado.** Las rutas firmadas `demo/pagos/{externalId}/checkout` y `demo/pagos/{externalId}/resultado` (`App\Http\Controllers\Demo\SimulatedCheckoutController`) simulan la pantalla de pago de un proveedor real; la entrega del resultado al dominio ocurre en proceso, vía `App\Jobs\DeliverSimulatedProviderWebhook`, sin HTTP ni DNS de por medio.
7. **Tests.** `tests/Feature/Payments/*` cubre iniciación, webhook, idempotencia, expiración, reconciliación y concurrencia (dos webhooks simultáneos para el mismo evento, una expiración concurrente con un webhook en vuelo).

### Fase 10 — Tiempo real

1. Instalar Reverb.
2. Crear evento de reserva.
3. Canal privado por empresa.
4. Autorizar canal.
5. Actualizar calendario en vivo.

### Fase 10.5 — Listado público de negocios

Cierra la brecha marcada en la Fase 11 (§ punto de partida): hoy no existe ni backend ni frontend
para que un cliente descubra qué negocios existen — solo se puede llegar a `/negocios/{slug}`
sabiendo el slug de antemano.

1. **Backend.** `Public\BusinessController::index()`, ruta `GET /negocios`, sin autenticación (mismo
   criterio que `/negocios/{slug}` — solo `/reservar` exige sesión). Devuelve los negocios con
   `is_active = true`, ordenados por nombre, proyectando `id`, `name`, `slug`.
2. **Frontend.** `Public/Business/Index.jsx`, mismo patrón visual mínimo que `Show.jsx` (lista +
   `PublicLayout`, sin librería de componentes) — cada negocio linkea a `/negocios/{slug}`.
3. **Punto de entrada.** `Pages/Home.jsx` gana un link a `/negocios` (hoy solo tiene "Ver mis
   reservas" para clientes logueados) — sin esto, el listado no tiene desde dónde llegar sin escribir
   la URL a mano.
4. **Test.** Un negocio con `is_active = false` no aparece en el listado — mismo invariante que ya
   protege `/negocios/{slug}` vía `BindPublicBusiness`.

Fuera de alcance: paginación (la escala de demo no la necesita), búsqueda o filtro, categorías de
negocio, y cualquier diseño visual — eso es Fase 11.

### Fase 11 — Rediseño y experiencia frontend

**Objetivo:** convertir una aplicación ya funcional en una demo SaaS profesional, coherente y presentable, sin rediseñar el backend por motivos visuales.

> Mantener ReservaHub como proyecto Laravel orientado a backend, y a la vez lograr que la aplicación completa sea comprensible, creíble, agradable y demostrable para un reclutador o revisor técnico sin que el autor tenga que explicar cada pantalla a mano.

El frontend dejó de ser aceptable como "la interfaz mínima para ejercitar los CRUD". Esta fase no define todavía el diseño final: define el alcance y las preguntas que el brainstorming posterior tiene que resolver.

**Estado: Hecha** (ver la tabla de estado más arriba). Lo que sigue quedó como registro del alcance y las preguntas que guiaron el brainstorming y la planificación —no como especificación visual final—; el diseño aprobado, el plan de ejecución y el resultado real viven en `docs/superpowers/plans/2026-08-23-fase11-redesign-frontend.md`, `docs/superpowers/specs/2026-08-23-fase11-redesign-frontend-design.md` y en el propio frontend (`resources/js/`). El **Punto de partida real** de la subsección siguiente describe el frontend **antes** de esta fase, a propósito: es la línea de base contra la que se auditó, no el estado actual del repositorio.

> **Superado por la Fase 12 (§12.17, §12.22).** El reinicio completo pasó de diario a **semanal
> (lunes 00:00 America/Argentina/Buenos_Aires)**. Las credenciales publicadas y el buzón de Mailpit
> siguen restaurándose **diariamente**. El texto de abajo se conserva como registro de la decisión
> original de la Fase 11.

#### Punto de partida real (verificado en el repositorio)

- 17 páginas Inertia en `resources/js/Pages/` y 4 componentes en `resources/js/Components/` (`AuthCard`, `DashboardLayout`, `InputError`, `PublicLayout`).
- Tailwind CSS 4 vía `@tailwindcss/vite`, sin librería de componentes; `resources/css/app.css` tiene 9 líneas y solo declara la familia tipográfica.
- `Pages/Home.jsx` es una portada mínima (`<h1>ReservaHub</h1>` más un enlace a `/negocios` y, para clientes logueados, otro a `/mis-reservas`). No hay landing pública.
- `Pages/Dashboard/Index.jsx` es un placeholder que dice explícitamente que el dashboard real llega en una fase posterior; `DashboardController` solo pasa el nombre del negocio. **El dashboard del alcance funcional (§2) no está implementado y ninguna fase previa lo reclama** — esta fase decide qué se construye y con qué datos reales.
- Áreas ya conectadas: autenticación (login, registro, verificación, recuperación, reset), invitaciones de empleados, servicios, empleados, horarios, pausas, licencias, reservas del panel con su ciclo de vida completo (confirmar, cancelar, completar, ausencia, reprogramar), página pública de negocio, reserva pública y "mis reservas" del cliente.
- Sin UI (aunque el backend existe o el dominio lo requiere): notificaciones en base de datos, ajustes del negocio, perfil/cuenta del usuario, gestión de tokens de API.
- Listado/descubrimiento de negocios (`GET /negocios` y `GET /api/businesses`) ya existe: lo construyó la Fase 10.5 — la Fase 11 rediseña esa pantalla como cualquier otra ya conectada.
- Pagos (Fase 9) y tiempo real (Fase 10) ya existen: la Fase 11 rediseña también la tabla de reservas que ya se actualiza sola — **solo se rediseña lo que esté implementado cuando la fase empiece**.

#### Workflow obligatorio al ejecutar esta fase

No empezar escribiendo UI. La ejecución arranca así:

1. Leer el repositorio y el frontend actual.
2. Inspeccionar rutas, layouts, componentes, páginas Inertia, endpoints/actions de Laravel y flujos de usuario implementados.
3. Revisar qué fases están realmente completas (tabla de estado de esta sección, contrastada con el código).
4. Levantar la aplicación.
5. Inspeccionar pantallas representativas en el navegador.
6. Invocar la skill `superpowers:brainstorming`.
7. Hacer **una** pregunta genuinamente abierta de diseño/producto por vez.
8. Usar la skill de diseño frontend instalada (hoy `frontend-design`) para el trabajo visual/UX; si el nombre en runtime cambió, inspeccionar las skills instaladas y usar la correcta en lugar de adivinar o sustituir por otro workflow.
9. Comparar enfoques realistas donde la decisión sea significativa.
10. Presentar la experiencia frontend propuesta para aprobación.
11. Recién con el diseño aprobado, escribir el plan de implementación.

Superpowers sigue a cargo del proceso (brainstorming y planificación); la skill de diseño frontend se usa para elevar la calidad visual de **esta** aplicación, no para generar una plantilla SaaS genérica desconectada del dominio.

#### 11.1 Auditoría (antes de rediseñar)

Clasificar cada pantalla y componente existente en: *mantener*, *refinar visualmente*, *reestructurar UX*, *funcionalmente incompleta*, *sin conexión con el frontend*, *redundante*, *inconsistente*, *inaccesible*, *obsoleta*. No asumir que todo se reescribe.

Cubre: inventario de UI actual, auditoría de conexión backend↔frontend, auditoría por rol, auditoría de dashboards y auditoría de flujos de demo.

Reglas: preservar el comportamiento que ya funciona salvo razón concreta; **no tocar reglas de negocio de Laravel ya validadas** por motivos de rediseño; si falta la conexión frontend de una capacidad de backend que ya existe, conectarla bien; si falta de verdad un endpoint/action, declararlo explícitamente en lugar de taparlo con comportamiento simulado en el frontend.

#### 11.2 Conexiones backend↔frontend faltantes

Pregunta obligatoria de la auditoría: *¿el backend ya expone funcionalidad útil que el frontend no hace accesible ni comprensible?* Buscar acciones implementadas sin UI usable, estado del backend que no se muestra, transiciones importantes que no se pueden disparar desde la interfaz, falta de feedback de validación/error, notificaciones sin representación visual, estado de pago almacenado pero mal comunicado, capacidades de disponibilidad mal expuestas, comportamiento multi-tenant incomprensible desde la interfaz, y features de tiempo real sin feedback visible.

No agregar UI para cada endpoint automáticamente: decidir por relevancia de producto y valor de demo.

#### 11.3 Experiencia pública y primera impresión

Requisito mayor de la fase: lo que ocurre **antes** de autenticarse. Quien llega sin cuenta debe entender enseguida que ReservaHub es una aplicación SaaS de portfolio/demo, qué problema resuelve, qué tipos de negocio representa, qué flujos importantes puede probar, que el entorno tiene datos de demostración e integraciones simuladas, que no debe usarse con información comercial o de clientes real, y cómo explorar la demo de forma segura.

A resolver en brainstorming (no hardcodear ahora): explicación concisa del producto, qué capacidades de backend destacar, aviso de demo, llamadas a registrarse y a iniciar sesión, si conviene ofrecer cuentas de demo predefinidas, cuánta información técnica va en la página pública, y cómo explicar el comportamiento simulado de pagos/emails sin abrumar a un visitante no técnico. Debe parecer una demo de producto real, no una pantalla de depuración.

#### 11.4 Guía de uso de la demo y términos

Evaluar e implementar la explicación de uso adecuada —guía de demo, términos de uso, aviso de entorno de demostración y aviso de datos ficticios—, combinados o separados según decida el brainstorming. Como mínimo debe quedar claro que: es un entorno de demostración/portfolio; no hay que cargar datos sensibles, de clientes ni de pago reales; algunas integraciones externas están simuladas; los datos de demo pueden reiniciarse; el envío de email puede capturarse localmente en lugar de llegar a destinatarios reales; y que el entorno demuestra el comportamiento del software, no es un servicio comercial en producción.

No fabricar garantías legales ni presentar los términos como asesoramiento legal profesional: tono apropiado para una aplicación de portfolio.

#### 11.5 Flujo de email de demo (Mailpit)

Verificar el entorno real antes de documentar: si Mailpit está presente, cuál es su URL/puerto real, qué notificaciones se pueden demostrar por ahí, y si esa interfaz es solo local. Hoy `compose.yaml` incluye `mailpit` con el dashboard en `FORWARD_MAILPIT_DASHBOARD_PORT` (8025 por defecto en el checkout principal) — **no hardcodear el puerto de memoria**, leerlo de la configuración vigente.

El flujo local documentable es del estilo: registrarse / reservar / pedir reset → abrir la interfaz de Mailpit → inspeccionar el mensaje capturado → seguir el enlace o verificar la notificación.

En el modelo de demo pública que aprobó esta fase, el buzón de Mailpit **se expone públicamente a propósito**: es superficie de producto, no solo tooling local. Localmente en este worktree vive en `http://localhost:8026`; en producción se sirve en un hostname público separado, enlazado como CTA desde `/como-funciona` a través de la variable de build pública `VITE_DEMO_MAIL_URL` (ver `docs/DEPLOYMENT_HANDOFF.md` §3, §9, §10). Lo que sigue siendo responsabilidad del workflow externo de operaciones es la decisión concreta de hostname/subdominio y su exposición real en el servidor — no si Mailpit debe ser público, que ya está decidido aquí.

#### 11.6 Demo multi-rol y multi-tenant

Roles reales implementados (`App\Enums\Role`): `owner`, `admin`, `employee`, `customer`, más el aislamiento entre negocios. Inspeccionar los permisos realmente implementados en vez de confiar en ejemplos viejos del roadmap.

El brainstorming decide el mejor flujo para demostrar esos roles y documentar un procedimiento práctico de prueba local. Donde sea útil, recomendar ventanas normales/incógnito o perfiles de navegador separados para observar varias sesiones autenticadas a la vez:

```text
ventana normal / perfil
→ owner/admin

ventana de incógnito
→ cliente

segundo navegador / perfil
→ empleado
```

Verificar antes el comportamiento real de sesión/autenticación. **No** construir funcionalidad artificial de multi-sesión dentro de ReservaHub solo para evitar abrir varios contextos de navegador.

#### 11.7 Diseño y sistema de diseño

Dirección visual definida por brainstorming + skill de diseño frontend. Requisitos: profesional, cohesivo, claramente una aplicación SaaS/de negocio, calidad de portfolio, responsive, accesible, visualmente superior al scaffolding por defecto, usable antes que decorativo, y consistente entre el área pública y la autenticada. El diseño debe salir del dominio de turnos y negocios de ReservaHub.

Evitar: páginas SaaS genéricas con degradados de IA, glassmorphism excesivo, tarjetas de dashboard sin significado, decoraciones de terminal/código, gráficos decorativos con datos inventados, estilos de componente inconsistentes y animación que estorbe la productividad de un CRUD.

Documentar un sistema coherente para al menos: tipografía, espaciado, color, estados semánticos, superficies, bordes, sombras, radios, botones, campos, tablas, tarjetas, badges/estados, navegación, diálogos, comportamiento responsive, iconos y estados de foco. Reutilizar las primitivas buenas que ya existen; no reconstruir componentes por novedad.

#### 11.8 Estados de UX y calidad de aplicación

Diseño consistente para: carga, estado vacío, éxito, error de validación, permiso denegado, fallo de red/servidor, confirmación destructiva, acciones deshabilitadas, operaciones pendientes, paginación, filtros, búsqueda, tablas, formularios, diálogos/modales, notificaciones/toasts y navegación responsive. Revisar si las pantallas CRUD actuales comunican estos estados de forma consistente (hoy no hay sistema de toasts ni de estados compartido).

#### 11.9 Dashboards

No dar por final el dashboard actual (es un placeholder). Preguntar en brainstorming: qué rol necesita dashboard, qué información le sirve de verdad, cuál ya existe en el backend, qué métricas son reales y confiables, qué acciones deben estar a mano, cómo se ven los estados vacíos, si carga y error se entienden, y si se están mostrando métricas de vanidad para llenar la pantalla.

Candidatos, solo con datos reales: reservas de hoy, próximos turnos, cancelaciones, ingresos estimados, señas/pagos pendientes (cuando exista la Fase 9), servicios más solicitados, actividad de empleados, disponibilidad y estado de recordatorios. **No inventar analíticas por estética.**

#### 11.10 Accesibilidad y responsive

Exigir: navegación por teclado, foco visible, etiquetas y errores de formulario accesibles, jerarquía de encabezados sensata, contraste, layouts responsive, comportamiento de tablas en pantallas angostas, accesibilidad de modales/diálogos, respeto por `prefers-reduced-motion` si se agrega movimiento y áreas táctiles claras. Probar flujos representativos en anchos de escritorio y móvil. Es una aplicación de negocio: la usabilidad va antes que los efectos visuales.

#### 11.11 Datos de demo y escenarios guiados

Coordinar con la estrategia de datos de demo segura (§10 del documento y `DemoSeeder`). El diseño debe hacer práctico demostrar escenarios completos: crear cuenta o iniciar sesión, configurar un servicio, configurar la disponibilidad de un empleado, crear una reserva de cliente, observarla desde otro rol/sesión, reprogramar o cancelar, inspeccionar el email de notificación en la herramienta de demo correspondiente, simular el flujo de pago donde esté implementado y observar cambios en tiempo real si la Fase 10 lo soporta.

Los escenarios se derivan de funcionalidad realmente terminada. Conviene documentar un conjunto chico de escenarios canónicos para que un revisor sepa qué probar.

#### 11.12 Frontera técnica

El rediseño de frontend no puede convertirse en una reescritura de la arquitectura Laravel. Preservar Laravel + Inertia + React/Vite salvo que el brainstorming encuentre un defecto técnico concreto. **No** introducir Next.js, una segunda SPA, un despliegue frontend independiente ni otro framework de frontend por el hecho de rediseñar la UI.

#### 11.13 Rendimiento

Revisar build/bundle, JavaScript innecesario, assets pesados, manejo de imágenes, rerenders evitables, rendimiento de tablas/listas, requests caros de dashboard y N+1 evidentes que la nueva UI destape. Si el trabajo de frontend revela un problema real de backend, registrarlo y arreglarlo con alcance justificado en vez de disimularlo en la UI. No optimizar cuellos de botella teóricos.

#### 11.14 Verificación

Determinar la verificación adecuada a partir del stack de tests real (PHPUnit + tests de feature Inertia). Debe cubrir flujos críticos representativos y riesgos de regresión: suite verde, build de frontend, límites de rol, consistencia visual, responsive, accesibilidad y ninguna funcionalidad Laravel/Inertia rota.

Evaluar en la planificación si conviene automatización E2E de navegador. **No** agregar Playwright/Cypress automáticamente por ser una fase de frontend: si aporta valor real para la demo madura, comparar enfoques y pedir aprobación antes de introducir esa infraestructura. La verificación manual en navegador debe incluir explícitamente los flujos multi-rol con ventanas privadas.

#### 11.15 Documentación entregable

Producir o actualizar: decisiones de diseño frontend, flujos de usuario/demo, uso del entorno de demo, flujo local de Mailpit si aplica, workflow de prueba multi-rol, capturas, validación de accesibilidad/responsive, verificación de frontend y limitaciones intencionales conocidas. Sin exponer secretos ni información interna del servidor.

#### 11.16 Criterios de aceptación

- Un visitante entiende en segundos que ReservaHub es un SaaS de reservas de portfolio/demo.
- Entiende cómo explorar la demo de forma segura.
- La experiencia sin autenticar se ve intencional y profesional.
- Los flujos autenticados centrales comparten un sistema visual coherente.
- La funcionalidad de backend pensada para personas es alcanzable y comprensible desde el frontend.
- Los dashboards muestran información real y útil, no relleno.
- Los estados de carga, vacío, éxito, validación, permiso y fallo son coherentes.
- Los flujos de owner/admin/employee/customer son prácticos de demostrar donde esos roles existen.
- El flujo local de email de demo está documentado con exactitud si se usa.
- Se puede demostrar más de un rol sin destruir sesiones útiles todo el tiempo.
- El comportamiento responsive es deliberado.
- Hay una línea base de accesibilidad verificada.
- No hace falta ningún dato real de usuario o de pago.
- Ningún comportamiento de backend se rompió con el rediseño.
- El proyecto sigue demostrando fuerza en Laravel/backend y no se presenta como una vidriera de frontend.

### Fase 12 — Release readiness, GitHub y preparación para producción

**Objetivo:** cerrar ReservaHub como una release pública, reproducible y desplegable, dejando dentro del repositorio todo lo que pertenece a la aplicación y reduciendo al mínimo las decisiones que tendrá que tomar posteriormente el agente de operaciones sobre el VPS real.

El destino previsto es un **VPS Linux de OVHcloud**, administrado por el autor y compartido entre distintos proyectos Docker:

```text
VPS Linux
│
├── ReservaHub
├── Portfolio
├── proyectos futuros
└── infraestructura común del host
```

ReservaHub debe funcionar como **un proyecto Docker aislado** dentro de ese servidor.

La Fase 12 prepara:

- repositorio público en GitHub;
- CI con GitHub Actions;
- imágenes Docker productivas;
- publicación de imágenes en GitHub Container Registry;
- runtime productivo con Nginx + PHP-FPM;
- Compose productivo portable;
- contrato de variables de entorno;
- healthchecks;
- comandos seguros para mantener la demo;
- reset semanal de datos funcionales;
- restauración diaria de credenciales demo;
- limpieza diaria de Mailpit documentada;
- actualización del countdown de la demo;
- README propio;
- documentación de deployment;
- procedimiento portable de deployment y rollback;
- release `v1.0.0`.

La Fase 12 **no compra ni configura todavía el VPS real ni Cloudflare**.

---

#### 12.1 Decisiones cerradas

Estas decisiones ya están aprobadas y no deben volver a debatirse salvo que la inspección del repositorio encuentre una contradicción técnica real.

##### Hosting previsto

VPS Linux de OVHcloud.

El VPS será multiproyecto y cada aplicación tendrá su propio proyecto Docker aislado.

La ubicación física, estructura `/srv`, volúmenes, firewall, SSH y configuración general del host se deciden posteriormente sobre la máquina real.

##### Dominio previsto

```text
lucianogonzalez.dev
reservahub.lucianogonzalez.dev
reservahub-mail.lucianogonzalez.dev
```

- `lucianogonzalez.dev`: portfolio futuro.
- `reservahub.lucianogonzalez.dev`: ReservaHub.
- `reservahub-mail.lucianogonzalez.dev`: Mailpit público de la demo.

El dominio se administrará mediante Cloudflare.

##### Repositorio

ReservaHub será un repositorio público en GitHub.

Todavía no existe el repositorio remoto: esta fase debe crearlo y dejarlo correctamente configurado.

Las imágenes Docker de release publicadas en GHCR también serán públicas.

##### Frontend

ReservaHub sigue siendo:

```text
Laravel
+
Inertia
+
React
+
Vite
```

Node y pnpm se utilizan solamente para compilar el frontend.

**No existe un proceso Node en producción.**

`public/build` forma parte del artefacto productivo.

##### Runtime HTTP

El runtime productivo será:

```text
Nginx
   ↓
PHP-FPM
   ↓
Laravel
```

`php artisan serve` queda exclusivamente para desarrollo.

No introducir:

- Laravel Octane;
- FrankenPHP;
- RoadRunner;

salvo que exista una necesidad técnica concreta no conocida actualmente.

##### Deployment

El primer deployment al VPS será manual.

La aplicación debe quedar preparada para que posteriormente el agente de operaciones pueda ejecutar:

```text
release
→ pull de imágenes
→ configuración
→ migraciones
→ arranque
→ smoke
```

No implementar todavía deployment automático al VPS.

Una vez demostrado el procedimiento manual podrá evaluarse CD automático.

##### Entrada pública

La Fase 12 no decide definitivamente entre:

```text
Cloudflare Proxy
→ reverse proxy del VPS
```

y:

```text
Cloudflare Tunnel
→ VPS
```

Esa decisión pertenece al agente de operaciones que inspeccione el VPS real.

##### Observabilidad

No agregar en esta fase:

- Grafana;
- Prometheus;
- Uptime Kuma;
- Coolify.

ReservaHub debe entregar healthchecks, logs utilizables y smoke checks.

La observabilidad global del servidor puede agregarse posteriormente a nivel del VPS.

##### Tests E2E

**No agregar:**

- Playwright;
- Cypress;
- Vitest;
- Jest.

ReservaHub ya posee una suite Laravel extensa y la Fase 11 realizó revisión real en navegador.

La Fase 12 utiliza:

- PHPUnit;
- tests Feature/Unit existentes;
- tests de concurrencia;
- tests de integración;
- build frontend;
- validación Docker;
- smoke tests;
- comprobación manual del runtime productivo.

---

#### 12.2 Auditoría antes de publicar GitHub

Antes de hacer público el repositorio se debe revisar **el historial Git completo**, no solamente el working tree actual.

Buscar específicamente:

- `.env`;
- passwords reales;
- claves API;
- tokens;
- claves privadas;
- secretos de Cloudflare;
- credenciales de OVH;
- claves SSH;
- dumps de base de datos;
- datos reales;
- logs con información sensible;
- archivos temporales;
- credenciales externas;
- secretos que hayan sido eliminados posteriormente del repositorio.

Que un archivo esté actualmente en `.gitignore` no garantiza que nunca haya estado versionado.

Si algún secreto estuvo versionado:

1. considerarlo comprometido;
2. eliminarlo del historial cuando realmente corresponda;
3. rotarlo antes de publicar el repositorio.

Verificar además que Git no incluya artefactos innecesarios:

```text
.env
vendor/
node_modules/
storage/logs/*
public/hot
dumps
backups
archivos temporales
builds locales no requeridos
```

No reescribir el historial sin motivo real.

La auditoría debe producir una conclusión explícita:

```text
SAFE TO PUBLISH
```

o listar los bloqueos concretos que deban resolverse primero.

---

#### 12.3 Crear y organizar GitHub

Crear un repositorio público.

Nombre recomendado:

```text
reservahub
```

Configurar:

- descripción;
- README;
- topics;
- rama principal `main`;
- GitHub Actions;
- GitHub Packages / GHCR.

Topics sugeridos:

```text
laravel
php
react
inertia
postgresql
redis
docker
reverb
websockets
saas
booking-system
portfolio
```

Agregar el remote:

```text
origin
```

y subir el historial actual.

No crear issues o PR históricos ficticios para simular actividad anterior.

Las futuras issues deben representar trabajo real.

Cuando CI esté funcionando, proteger `main` de forma liviana:

- no permitir force push;
- no permitir borrado accidental;
- requerir checks de CI en Pull Requests cuando corresponda;
- permitir administración del propietario.

No agregar burocracia innecesaria para un proyecto personal mantenido por una sola persona.

---

#### 12.4 Runtime Docker de producción

El `compose.yaml` actual basado en Laravel Sail continúa siendo exclusivamente el entorno de desarrollo.

**No convertir Sail en producción.**

Crear un runtime productivo independiente, portable y reutilizable.

Arquitectura prevista:

```text
ReservaHub Docker project
│
├── web
│   └── Nginx
│
├── app
│   └── PHP-FPM + Laravel
│
├── queue
│   └── queue:work
│
├── scheduler
│   └── schedule:work
│
├── reverb
│   └── reverb:start
│
├── pgsql
│   └── PostgreSQL
│
├── redis
│   └── Redis
│
└── mailpit
    └── Mailpit
```

No compartir PostgreSQL, Redis o Mailpit con otros proyectos del futuro VPS.

Cada aplicación futura debe poder tener su propio stack independiente.

---

#### 12.5 Imagen productiva Laravel

Crear una imagen Docker específica de producción independiente de Sail.

Debe contener:

- PHP 8.5;
- extensiones Laravel necesarias;
- soporte PostgreSQL;
- soporte Redis;
- soporte requerido por Reverb;
- Composer dependencies de producción;
- código Laravel;
- frontend compilado;
- OPcache;
- PHP-FPM.

Debe utilizar multi-stage build.

Conceptualmente:

```text
Composer stage
       +
Node 24 / pnpm stage
       ↓
PHP-FPM runtime final
```

La imagen final no debe contener:

- Node runtime;
- pnpm runtime;
- `node_modules`;
- dependencias Composer de desarrollo;
- `.env`;
- secretos;
- claves privadas;
- archivos temporales de build.

`public/build` sí debe quedar incorporado.

La misma imagen Laravel debe reutilizarse para:

```text
app
queue
scheduler
reverb
```

cambiando únicamente el comando del contenedor.

Evitar construir cuatro imágenes Laravel distintas.

La imagen productiva debe poder identificarse por versión de release y por commit.

---

#### 12.6 Nginx + PHP-FPM

##### Nginx

Nginx es el entrypoint HTTP interno del proyecto.

Debe:

- servir assets estáticos;
- enviar PHP a PHP-FPM;
- soportar correctamente las rutas Laravel;
- respetar `public/index.php` como front controller;
- preservar WebSocket Upgrade cuando actúe como gateway para Reverb;
- no exponer PostgreSQL;
- no exponer Redis;
- no hardcodear el hostname público cuando pueda evitarse.

Cuando sea razonable, Nginx puede actuar como gateway interno tanto para Laravel como para Reverb, dejando una única superficie HTTP del proyecto hacia el host.

La implementación debe verificar primero el contrato real de Laravel Reverb antes de definir el proxy.

##### PHP-FPM

Configurar un pool productivo explícito.

Debe permitir controlar:

- cantidad máxima de workers;
- workers iniciales;
- workers idle;
- requests máximas por worker;
- timeout;
- límites razonables de memoria.

No dimensionarlo según la máquina local de desarrollo.

Los valores iniciales deben ser conservadores y posteriormente podrán ajustarse después de medir el VPS real.

Habilitar OPcache apropiadamente.

El objetivo es que el consumo de memoria y la concurrencia sean controlables y predecibles.

---

#### 12.7 Procesos Laravel productivos

Los mismos artefactos de aplicación deben poder ejecutar:

```text
php-fpm
php artisan queue:work
php artisan schedule:work
php artisan reverb:start
```

mediante comandos diferentes.

No duplicar código ni imágenes.

##### Queue

Mantener Redis como backend de cola.

Configurar el worker de forma explícita y apropiada para proceso de larga duración.

Documentar:

- queue usada;
- `--tries`;
- timeout;
- estrategia de restart;
- comportamiento ante deploy.

##### Scheduler

Mantener un proceso permanente para Laravel Scheduler.

La aplicación debe seguir siendo dueña de:

```text
schedule:list
```

y de las tareas definidas dentro de Laravel.

El host solamente es responsable de mantener vivo el proceso indicado por el runtime.

##### Reverb

Reverb continúa siendo el servidor de tiempo real.

No cambiar:

- arquitectura;
- canales;
- payloads;
- autorización;
- modelo staff-only aprobado en Fase 10.

La Fase 12 solamente lo hace ejecutable de manera productiva.

---

#### 12.8 Persistencia

##### PostgreSQL

Requiere volumen persistente.

Los datos deben sobrevivir:

- restart;
- recreación del contenedor;
- actualización de imágenes.

##### Redis

Se utiliza como infraestructura de cola.

Debe existir una estrategia explícita ante reinicios del contenedor.

No exponerlo públicamente.

Evaluar la configuración mínima necesaria para reducir pérdida innecesaria de jobs ante reinicios normales.

No sobredimensionar Redis para un proyecto de demo.

##### Mailpit

Puede utilizar almacenamiento persistente para conservar mensajes entre reinicios normales del contenedor.

La limpieza funcional diaria se gestiona aparte.

##### Laravel

Actualmente ReservaHub no permite uploads de usuario.

Por lo tanto no necesita almacenamiento persistente de uploads.

Los assets frontend viven dentro de la imagen.

No crear un volumen persistente innecesario solo por costumbre.

---

#### 12.9 Compose productivo portable

Crear un Compose productivo del proyecto, por ejemplo:

```text
compose.production.yaml
```

Debe ser portable.

Puede definir:

- servicios;
- networks;
- volumes lógicos;
- healthchecks;
- restart policies;
- variables requeridas;
- dependencias internas;
- imágenes versionables.

No debe definir:

- hostname final obligatorio;
- IP pública;
- puertos públicos adivinados;
- paths `/srv` específicos;
- Cloudflare;
- tokens;
- secretos productivos;
- firewall;
- systemd;
- configuración específica de OVH.

El agente del VPS podrá crear posteriormente un pequeño override si necesita adaptar detalles del host.

PostgreSQL y Redis deben quedar solamente en red interna.

Mailpit debe ser alcanzable por el gateway elegido posteriormente, pero no debe exigir un puerto público hardcodeado.

---

#### 12.10 Healthchecks y restart policies

Los procesos permanentes deben utilizar una política apropiada, preferentemente:

```text
restart: unless-stopped
```

cuando corresponda.

Agregar healthchecks reales.

Como mínimo:

##### Aplicación

```text
GET /up
```

##### PostgreSQL

```text
pg_isready
```

##### Redis

```text
redis-cli ping
```

##### Mailpit

Usar un endpoint HTTP real soportado por la versión instalada.

##### Reverb

Usar un check real de proceso, puerto o endpoint soportado.

##### PHP-FPM / Nginx

El healthcheck público de la aplicación debe confirmar que la cadena:

```text
Nginx
→ PHP-FPM
→ Laravel
```

está respondiendo.

No crear healthchecks ficticios que siempre devuelvan éxito.

Los healthchecks no sustituyen los smoke tests.

---

#### 12.11 Contrato de entorno

Actualizar:

```text
docs/DEPLOYMENT_HANDOFF.md
```

con una tabla completa de variables.

Clasificarlas como:

```text
secret
runtime public
build-time public
internal
development-only
```

Revisar como mínimo:

```text
APP_ENV
APP_KEY
APP_DEBUG
APP_URL

DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD

REDIS_HOST
REDIS_PORT
REDIS_PASSWORD

QUEUE_CONNECTION

MAIL_MAILER
MAIL_HOST
MAIL_PORT
MAIL_USERNAME
MAIL_PASSWORD
MAIL_FROM_ADDRESS
MAIL_FROM_NAME

REVERB_APP_ID
REVERB_APP_KEY
REVERB_APP_SECRET
REVERB_HOST
REVERB_PORT
REVERB_SCHEME

VITE_REVERB_APP_KEY
VITE_REVERB_HOST
VITE_REVERB_PORT
VITE_REVERB_SCHEME

VITE_DEMO_MAIL_URL

PAYMENTS_WINDOW_MINUTES
PAYMENTS_RECONCILE_BATCH
PAYMENTS_RECONCILE_CADENCE_MINUTES
```

Las variables `VITE_*` son públicas por definición.

Nunca colocar secretos en una variable `VITE_*`.

Actualizar `.env.example` para reflejar correctamente todo el contrato portable sin incluir ningún valor secreto real.

---

#### 12.12 Configuración del modo demo público

Agregar una configuración explícita para identificar el deployment público de demo.

Como mínimo:

```text
DEMO_PUBLIC_MODE=true
```

Los comandos destructivos de demo nunca deben depender únicamente de:

```text
APP_ENV=production
```

Debe existir una guarda específica.

Agregar una segunda forma independiente de confirmar que se está apuntando al entorno demo correcto.

El mecanismo exacto debe definirse después de inspeccionar la configuración real, pero no debe depender solamente de una única variable booleana fácil de activar accidentalmente.

La contraseña publicada para las cuentas de demo debe tener una fuente canónica reutilizable.

Debe evitarse mantener passwords diferentes hardcodeadas de forma independiente en:

- `DemoSeeder`;
- `/como-funciona`;
- restauración diaria de credenciales.

La contraseña demo es pública por definición y no debe confundirse con un secreto productivo real.

---

#### 12.13 CI — GitHub Actions

Crear:

```text
.github/workflows/ci.yml
```

Debe ejecutarse en:

```text
push
pull_request
```

Debe comprobar como mínimo:

1. checkout;
2. PHP 8.5;
3. Composer;
4. `composer validate --strict`;
5. `composer install`;
6. PostgreSQL 18;
7. entorno de testing;
8. `php artisan key:generate`;
9. Node 24;
10. pnpm;
11. `pnpm install --frozen-lockfile`;
12. `pnpm build`;
13. `php artisan test`;
14. `vendor/bin/pint --test`;
15. `docker compose config -q`;
16. validación del Compose productivo;
17. build del Dockerfile productivo.

La suite actual utiliza:

```text
QUEUE_CONNECTION=sync
CACHE_STORE=array
```

por lo que CI no necesita obligatoriamente Redis para ejecutar PHPUnit.

CI no debe:

- conectarse al VPS;
- usar SSH productivo;
- modificar Cloudflare;
- desplegar producción;
- conocer secretos reales;
- depender de que exista todavía el VPS.

---

#### 12.14 Auditoría de dependencias

Ejecutar:

```text
composer audit
pnpm audit
```

Analizar los resultados.

Una vulnerabilidad alta o crítica que afecte runtime debe resolverse antes de `v1.0.0`.

No bloquear automáticamente la release por cualquier advisory exclusivo de tooling de desarrollo sin analizar el impacto real.

Documentar los advisories aceptados si los hubiera y explicar por qué no afectan runtime.

---

#### 12.15 GitHub Container Registry

Crear workflow de release para publicar las imágenes productivas en:

```text
ghcr.io
```

Trigger previsto:

```text
tag v*
```

Ejemplo:

```text
v1.0.0
```

Publicar imágenes públicas.

Preferir:

```text
reservahub-app
reservahub-web
```

si Nginx necesita su propia imagen.

La imagen Laravel debe reutilizarse para:

```text
app
queue
scheduler
reverb
```

Etiquetas mínimas:

```text
1.0.0
sha-<commit>
```

Puede existir:

```text
latest
```

como comodidad, pero producción nunca debe depender exclusivamente de `latest`.

El deployment debe poder fijar una versión o digest concreto.

Ninguna imagen puede contener secretos.

El workflow de release debe usar permisos mínimos de GitHub necesarios para publicar Packages.

---

#### 12.16 Primer deployment

La Fase 12 **no despliega todavía** ReservaHub al VPS.

Sin embargo debe dejar documentado un procedimiento manual claro.

Conceptualmente:

```text
1. elegir release
2. obtener imágenes
3. configurar entorno
4. levantar infraestructura
5. ejecutar migraciones
6. iniciar runtime
7. ejecutar bootstrap demo si corresponde
8. ejecutar smoke
9. verificar logs
```

Ese procedimiento será ejecutado posteriormente por el agente de operaciones sobre el VPS real.

Solo después de que el deployment manual haya demostrado ser reproducible se evaluará CD automático.

No agregar un GitHub Action que haga SSH al servidor en esta fase.

---

#### 12.17 Reset semanal de datos demo

El reset completo de la demo deja de ser diario.

Nuevo contrato:

```text
Lunes
00:00
America/Argentina/Buenos_Aires
```

El objetivo es permitir que los datos evolucionen naturalmente durante una semana.

Así pueden demostrarse correctamente:

- reservas que pasan al pasado;
- reprogramaciones;
- cancelaciones;
- pagos;
- recordatorios;
- cambios temporales;
- acciones de varios visitantes.

El estado funcional de la demo puede cambiar durante la semana y eso es comportamiento esperado.

El próximo lunes vuelve al dataset canónico.

---

#### 12.18 `php artisan demo:reset`

Crear:

```text
php artisan demo:reset
```

Es un comando destructivo.

Debe tener guardas fuertes.

Como mínimo debe comprobar:

```text
DEMO_PUBLIC_MODE=true
```

más una segunda comprobación independiente que reduzca el riesgo de ejecutarlo sobre una base equivocada.

En ejecución no interactiva debe requerir:

```text
--force
```

Si una guarda falla:

```text
ABORT
```

sin modificar ningún dato.

El comando debe:

1. impedir ejecuciones concurrentes;
2. evitar que jobs pendientes operen sobre el dataset viejo;
3. limpiar el estado funcional descartable de ReservaHub;
4. aplicar migraciones necesarias;
5. ejecutar exclusivamente `DemoSeeder`;
6. nunca ejecutar `DatabaseSeeder`;
7. limpiar caches o colas de ReservaHub que puedan referenciar IDs anteriores;
8. dejar el sistema consistente;
9. devolver exit code distinto de cero ante cualquier fallo.

Para este deployment completamente descartable puede utilizarse:

```text
migrate:fresh --force
```

si, después de inspeccionar el proyecto, resulta ser la solución más pequeña, explícita y segura.

Nunca utilizar ese mecanismo sobre datos que deban conservarse.

Mailpit no forma parte de este comando porque es otro servicio.

---

#### 12.19 Tests de `demo:reset`

Cubrir como mínimo:

- se niega sin modo demo;
- se niega con target incorrecto;
- exige la confirmación destructiva correspondiente;
- elimina datos creados posteriormente;
- ejecuta el dataset esperado;
- no ejecuta `DatabaseSeeder`;
- devuelve las cuentas demo al estado esperado;
- no deja jobs que apunten al dataset anterior;
- dos ejecuciones no pueden correr simultáneamente;
- un fallo no se reporta como éxito.

El test debe verificar el resultado real del dataset, no solamente que el comando termine con exit code `0`.

---

#### 12.20 Restauración diaria del acceso demo

El dataset completo se conserva durante una semana.

Pero las credenciales públicas no pueden quedar inutilizadas durante toda una semana si un visitante utiliza el flujo público de recuperación de contraseña.

Crear:

```text
php artisan demo:restore-access
```

Scheduling previsto:

```text
todos los días
00:00
America/Argentina/Buenos_Aires
```

Debe restaurar las cuentas de demo publicadas a su estado canónico.

Revisar como mínimo:

- password;
- email canónico cuando corresponda;
- `remember_token`;
- sesiones;
- tokens Sanctum;
- tokens de password reset.

Debe reutilizar la infraestructura de revocación de acceso ya existente cuando corresponda.

No debe resetear:

- reservas;
- pagos;
- servicios;
- horarios;
- historial funcional;
- cambios funcionales no relacionados con el acceso.

El resultado buscado es:

```text
historia funcional semanal
+
credenciales públicas recuperables diariamente
```

Agregar tests específicos.

---

#### 12.21 Mailpit público

Mailpit sigue formando parte intencional de la demo.

URL prevista:

```text
reservahub-mail.lucianogonzalez.dev
```

La aplicación ReservaHub no debe depender de que la interfaz de Mailpit esté disponible.

Mailpit se limpia:

```text
todos los días
00:00
America/Argentina/Buenos_Aires
```

Esto evita:

- acumulación excesiva;
- links viejos;
- ruido;
- mensajes de visitantes anteriores;
- demasiados enlaces de recuperación o invitación todavía visibles.

El vaciado de Mailpit es responsabilidad de operaciones.

`demo:reset` no debe llamar directamente a Mailpit.

El handoff debe declarar:

```text
SEMANAL
lunes 00:00
→ demo:reset

DIARIO
00:00
→ demo:restore-access
→ limpiar Mailpit
```

Configurar posteriormente límites razonables de retención y cantidad de mensajes cuando Mailpit los soporte.

Si Mailpit permite ocultar `Delete all`, hacerlo posteriormente desde operaciones.

La eliminación individual de mensajes continúa siendo una limitación aceptada de la demo compartida.

---

#### 12.22 Actualizar countdown y copy de la demo

La Fase 11 implementó un contador al siguiente reset completo diario.

Debe actualizarse al nuevo contrato semanal.

El countdown representa:

```text
próximo lunes
00:00
America/Argentina/Buenos_Aires
```

Actualizar:

- `DemoResetCountdown`;
- Home;
- `/como-funciona`;
- textos relacionados;
- documentación.

Debe quedar claro:

```text
Los datos completos de la demo se restauran semanalmente.

Las credenciales públicas y el buzón compartido se restauran diariamente.
```

No mostrar un segundo countdown para las credenciales.

Conservar las decisiones de accesibilidad aprobadas en Fase 11:

- sin segundos visibles;
- sin `aria-live`;
- sin pulso;
- sin animación de oferta;
- numerales tabulares;
- sin layout shift;
- sin afectar el foco;
- sin provocar reload.

El contador sigue siendo cliente puro e informativo.

No agregar:

- API de reset;
- polling;
- sincronización backend;
- WebSocket para el countdown.

La lógica debe calcular correctamente el próximo lunes 00:00 en:

```text
America/Argentina/Buenos_Aires
```

desde cualquier zona horaria del visitante.

---

#### 12.23 Actualización de documentación heredada de Fase 11

Buscar todas las referencias que todavía indiquen:

```text
reset diario completo
```

y actualizarlas.

Nuevo contrato:

```text
datos funcionales completos
→ reset semanal

credenciales demo
→ restauración diaria

Mailpit
→ limpieza diaria
```

Revisar especialmente:

- `01-reservahub.md`;
- `docs/DEPLOYMENT_HANDOFF.md`;
- especificaciones de Fase 11 si siguen siendo documentación vigente;
- README;
- `/como-funciona`;
- Home;
- comentarios de código relacionados con el countdown.

No modificar documentos históricos que explícitamente estén conservados como registro de una decisión anterior si eso destruyera su valor histórico; en esos casos dejar claro que fueron superseded por Fase 12.

---

#### 12.24 Handoff para VPS Linux

Reescribir:

```text
docs/DEPLOYMENT_HANDOFF.md
```

El documento deja de hablar de un home server como destino final.

El destino conceptual es:

```text
VPS Linux multiproyecto
```

inicialmente previsto en OVHcloud.

El documento explica **ReservaHub**, no cómo administrar Linux.

Debe responder:

- qué imágenes existen;
- qué contenedores ejecutar;
- qué procesos son obligatorios;
- qué debe persistir;
- qué puertos internos existen;
- qué variables necesita;
- cuáles son secretas;
- cuáles son públicas;
- cómo migrar;
- cómo arrancar;
- cómo ejecutar `DemoSeeder`;
- cómo ejecutar `demo:reset`;
- cómo ejecutar `demo:restore-access`;
- cómo limpiar Mailpit;
- cómo comprobar salud;
- cómo ejecutar smoke;
- cómo hacer rollback;
- qué datos pueden destruirse;
- qué datos deben persistir entre reinicios normales.

---

#### 12.25 Frontera entre repositorio y operaciones

##### ReservaHub entrega

```text
Dockerfile productivo
compose productivo portable
imágenes GHCR
Nginx
PHP-FPM
queue
scheduler
Reverb
PostgreSQL
Redis
Mailpit
healthchecks
restart policies
migraciones
DemoSeeder
demo:reset
demo:restore-access
contrato de entorno
CI
workflow de release
smoke checks
README
DEPLOYMENT_HANDOFF
procedimiento de rollback
```

##### El agente de operaciones del VPS decide y ejecuta

```text
distribución Linux
usuario del servidor
SSH
firewall
Docker del host
estructura física /srv
paths reales de volúmenes
reverse proxy del host
Cloudflare Proxy vs Tunnel
DNS
secretos reales
hostname real
certificados
scheduling real
limpieza real de Mailpit
retención real de Mailpit
reboot recovery
snapshots/backups del host
pull de release
migraciones reales
primer deployment
smoke real
rollback real
```

La Fase 12 no debe filtrar decisiones de host hacia el repositorio sin necesidad.

---

#### 12.26 Medición de recursos

Registrar las mediciones locales disponibles como referencia.

Medición actual del stack Docker:

```text
reposo       ≈ 0,30 GB
uso normal   ≈ 0,36 GB
pico medido  ≈ 0,45 GB
```

No tratarlas como garantía de producción.

La medición local utiliza:

- Laravel Sail;
- `artisan serve`;
- WSL2;
- dataset pequeño;
- un queue worker;
- pocas conexiones Reverb;
- PostgreSQL sin tuning de producción.

Producción utilizará Nginx + PHP-FPM y tendrá una configuración distinta.

Documentar que:

- el pool PHP-FPM será una de las variables principales de RAM;
- PostgreSQL debe dimensionarse explícitamente;
- cada queue worker adicional agrega consumo;
- Reverb debe observarse bajo uso real;
- Linux y Docker necesitan RAM propia;
- builds, logs y page cache también consumen recursos del host.

Como referencia inicial:

```text
ReservaHub solo
→ un VPS de 4 GB debería tener margen cómodo.

Servidor multiproyecto
→ 8 GB recomendado como punto de partida.
```

La aplicación no debe codificar ni depender de ese tamaño.

---

#### 12.27 Smoke checks portables

Crear un procedimiento de smoke reproducible.

Puede ser documentación o un script pequeño parametrizable.

Debe comprobar al menos:

```text
/up
/
/negocios
/como-funciona
login
dashboard
availability
creación de reserva
```

La verificación manual completa debe incluir además:

```text
queue
emails
Mailpit
pago simulado
webhook
confirmación de booking
Reverb con dos sesiones
```

No hardcodear:

- secretos;
- passwords productivos;
- hostname si puede recibirse como argumento;
- tokens.

Las credenciales demo públicas sí pueden documentarse como tales.

Un smoke automático no debe modificar destructivamente la demo salvo que esté diseñado explícitamente para ejecutarse contra un entorno descartable.

---

#### 12.28 Verificación del runtime productivo

Levantar localmente el stack productivo antes de considerarlo terminado.

Debe comprobarse que:

- no usa Laravel Sail;
- no usa `artisan serve`;
- no necesita Node en runtime;
- Nginx funciona;
- PHP-FPM funciona;
- OPcache está disponible;
- frontend compilado funciona;
- PostgreSQL funciona;
- Redis funciona;
- queue funciona;
- scheduler funciona;
- Reverb funciona;
- Mailpit funciona;
- las migraciones funcionan;
- `DemoSeeder` funciona;
- checkout simulado funciona;
- webhook simulado funciona;
- aplicación responde por `/up`.

No considerar suficiente que las imágenes simplemente compilen.

El stack tiene que arrancar y ser usable.

---

#### 12.29 Verificación final

Antes de cerrar la fase ejecutar como mínimo:

```text
php artisan test
vendor/bin/pint --test
composer validate --strict
composer audit

pnpm install --frozen-lockfile
pnpm build
pnpm audit

docker compose config -q
docker compose -f compose.production.yaml config -q
```

Además:

- build completo de imágenes productivas;
- tests de `demo:reset`;
- tests de `demo:restore-access`;
- tests/configuración del runtime productivo cuando corresponda;
- smoke del stack productivo;
- revisión de logs.

Ejecutar también las áreas críticas existentes:

- concurrencia de bookings;
- tenancy;
- Policies;
- API;
- payments;
- webhook idempotency;
- reconciliation;
- expiration;
- notifications;
- scheduler;
- Reverb/channel authorization.

Hacer smoke manual final.

**No agregar Playwright ni ningún framework E2E adicional.**

---

#### 12.30 README de GitHub

Reemplazar completamente el README stock de Laravel.

Debe explicar:

1. qué es ReservaHub;
2. problema que resuelve;
3. stack;
4. arquitectura;
5. roles;
6. multi-tenancy;
7. servicios;
8. disponibilidad;
9. prevención de solapamientos;
10. concurrencia;
11. reservas;
12. pagos simulados;
13. webhooks;
14. queue;
15. scheduler;
16. Reverb;
17. Docker;
18. CI;
19. API;
20. demo pública;
21. instalación de desarrollo;
22. ejecución de tests;
23. build frontend;
24. documentación disponible.

Agregar un diagrama de arquitectura.

Preferir Mermaid o un formato versionable.

Enlazar:

```text
docs/api.md
docs/DEPLOYMENT_HANDOFF.md
```

No afirmar que existe una demo pública desplegada hasta que realmente haya ocurrido el deployment.

Después del deployment real podrá agregarse:

```text
https://reservahub.lucianogonzalez.dev
```

al README y al About del repositorio.

---

#### 12.31 Capturas de portfolio

Utilizar únicamente capturas posteriores a la Fase 11.

Seleccionar pocas y fuertes:

- Home;
- Dashboard;
- Reservas;
- flujo público de booking;
- checkout simulado;
- Mailpit;
- responsive representativo.

No saturar el README.

Optimizar los archivos.

No incluir información real.

Las capturas deben representar el producto actual, no versiones viejas del frontend.

---

#### 12.32 Estado del repositorio antes de release

Antes de crear la release verificar:

```text
git status
→ limpio
```

No deben existir:

- cambios sin commit;
- archivos temporales;
- dumps;
- screenshots no deseadas;
- reports locales no destinados al repo;
- `.env`;
- secretos;
- artifacts del build que no deban versionarse.

Revisar también:

```text
git log
git remote -v
```

y confirmar que `origin` apunta al repositorio público correcto.

---

#### 12.33 Release `v1.0.0`

Crear `v1.0.0` solamente cuando:

- Fases 0–12 estén cerradas;
- GitHub público exista;
- `main` esté limpio;
- CI esté verde;
- suite completa esté verde;
- frontend build esté verde;
- runtime Docker productivo compile;
- stack productivo funcione localmente;
- GHCR funcione;
- paquetes GHCR sean públicos;
- README esté finalizado;
- documentación esté finalizada;
- `demo:reset` esté probado;
- `demo:restore-access` esté probado;
- auditoría Git no detecte secretos;
- smoke productivo local pase.

El tag:

```text
v1.0.0
```

dispara el workflow de release.

El futuro primer deployment a OVH debe desplegar exactamente esa release o sus digests.

No desplegar desde un working tree sin versionar.

No desplegar una imagen construida manualmente sin referencia a una release o commit conocido.

---

#### 12.34 Rollback

Documentar rollback por versión de imagen.

Ejemplo:

```text
actual:
1.0.1

rollback:
1.0.0
```

Rollback no reconstruye el código.

El operador selecciona la imagen o digest anterior y vuelve a levantar el stack.

Las migraciones destructivas requieren análisis independiente.

Las futuras migraciones deben considerar compatibilidad con rollback.

Para la primera release documentar claramente qué schema corresponde a `v1.0.0`.

Un rollback de imagen no debe asumir automáticamente que también puede revertirse el schema.

---

#### 12.35 Backups

ReservaHub público es una demo descartable.

No maneja datos comerciales reales.

Por decisión de producto:

**no se requiere backup histórico periódico de bookings de la demo.**

El reset semanal los destruye intencionalmente.

El VPS podrá utilizar snapshots antes de:

- cambios importantes;
- upgrades;
- migraciones de riesgo;
- modificaciones de infraestructura.

Esa política pertenece a operaciones.

El repositorio no incluye:

- cron de backup;
- rutas `/srv`;
- credenciales;
- buckets;
- secretos;
- configuración de proveedor de backup.

Futuros proyectos con datos reales tendrán políticas de backup distintas.

---

#### 12.36 Material de documentación técnica

Al cerrar la fase deben estar vigentes como mínimo:

```text
README.md
docs/api.md
docs/DEPLOYMENT_HANDOFF.md
.env.example
compose.yaml
compose.production.yaml
Dockerfile(s) productivos
.github/workflows/ci.yml
workflow de release GHCR
```

La documentación no debe contradecir:

- el reset semanal;
- la restauración diaria de credenciales;
- Mailpit público;
- el runtime Nginx + PHP-FPM;
- el modelo de VPS multiproyecto;
- la inexistencia de deployment automático.

Buscar documentación vieja que todavía diga:

```text
home server
reset diario completo
Mailpit privado
SMTP real obligatorio
artisan serve en producción
```

y actualizarla cuando corresponda.

---

#### 12.37 Fuera de alcance

La Fase 12 no:

- compra el dominio;
- compra el VPS;
- configura OVH;
- configura DNS;
- configura Cloudflare;
- configura Cloudflare Tunnel;
- instala Docker en el VPS;
- configura SSH;
- configura firewall;
- crea secretos productivos reales;
- ejecuta deployment remoto;
- configura systemd del host;
- configura cron del host;
- configura backups del host;
- instala Coolify;
- instala Grafana;
- instala Prometheus;
- instala Uptime Kuma;
- agrega Playwright;
- agrega Cypress;
- agrega Vitest;
- agrega Jest;
- agrega proveedor de pagos real;
- cambia la arquitectura de pagos;
- cambia la arquitectura de Reverb;
- agrega realtime para clientes;
- cambia Laravel/Inertia/React;
- agrega una segunda SPA;
- agrega SSR;
- convierte el portfolio en parte de ReservaHub.

---

#### 12.38 Criterios de aceptación

La Fase 12 termina cuando un operador que recibe:

```text
un VPS Linux limpio
+
acceso al dominio/Cloudflare
+
repositorio GitHub público
+
imágenes GHCR públicas
+
docs/DEPLOYMENT_HANDOFF.md
+
secretos de producción
```

puede desplegar ReservaHub sin tener que descubrir cómo funciona internamente la aplicación.

El operador todavía decide cómo administrar el host.

Pero no debe tener que inventar:

```text
qué imágenes ejecutar
qué procesos existen
qué persiste
qué variables necesita ReservaHub
qué puertos internos usa
cómo migrar
cómo sembrar
cómo resetear la demo
cómo restaurar las credenciales
cómo limpiar Mailpit
cómo comprobar salud
cómo hacer smoke
cómo hacer rollback
```

También debe cumplirse:

- el repositorio público no contiene secretos;
- CI valida el proyecto automáticamente;
- las imágenes de producción son reproducibles;
- el runtime productivo no depende de Sail;
- `artisan serve` no se utiliza en producción;
- Node no forma parte del runtime;
- el stack productivo funciona localmente;
- `demo:reset` no puede ejecutarse accidentalmente sobre un entorno no autorizado;
- `demo:restore-access` no destruye los datos funcionales de la semana;
- el countdown comunica correctamente el próximo reset semanal;
- Mailpit continúa siendo opcional para el funcionamiento central de ReservaHub;
- no se introdujo infraestructura E2E innecesaria;
- Fase 9 de pagos continúa intacta;
- Fase 10 de realtime continúa intacta;
- `v1.0.0` representa exactamente el código e imágenes que posteriormente se desplegarán.

Si alguna de estas respuestas exige estudiar el código y adivinar la arquitectura de ReservaHub, la Fase 12 todavía no está terminada.
