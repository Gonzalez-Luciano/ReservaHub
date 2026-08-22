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

Todavía no implementado: `Pages/Dashboard/Index.jsx` es un placeholder. Qué métricas se construyen, con qué datos reales y para qué rol se decide en la **Fase 11** (§7).

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
| 0 — Preparación | Hecha, salvo el pipeline de CI | Proyecto Laravel 13 + Sail, `.env.example`, Pint, pnpm. **Falta `.github/workflows`** → se cierra en la Fase 12 |
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
| 10.5 — Listado público de negocios | Pendiente | No existe ni backend ni frontend: `Public\BusinessController` solo tiene `show()`, no hay ruta `GET /negocios` |
| 11 — Rediseño y experiencia frontend | Pendiente | Frontend actual mínimo: 17 páginas Inertia y 4 componentes, `Pages/Home.jsx` es un `<h1>`, `Pages/Dashboard/Index.jsx` es un placeholder |
| 12 — Release readiness y handoff | En curso | `docs/DEPLOYMENT_HANDOFF.md` escrito. Pendientes: workflow de CI, README propio, seeder de demo con clientes y reservas, proxies de confianza para operar detrás de un proxy/tunnel |

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

**Esta fase no está implementada ni diseñada.** Lo que sigue es alcance, no especificación visual.

#### Punto de partida real (verificado en el repositorio)

- 17 páginas Inertia en `resources/js/Pages/` y 4 componentes en `resources/js/Components/` (`AuthCard`, `DashboardLayout`, `InputError`, `PublicLayout`).
- Tailwind CSS 4 vía `@tailwindcss/vite`, sin librería de componentes; `resources/css/app.css` tiene 9 líneas y solo declara la familia tipográfica.
- `Pages/Home.jsx` es una portada de una sola línea (`<h1>ReservaHub</h1>` más un enlace condicional). No hay landing pública.
- `Pages/Dashboard/Index.jsx` es un placeholder que dice explícitamente que el dashboard real llega en una fase posterior; `DashboardController` solo pasa el nombre del negocio. **El dashboard del alcance funcional (§2) no está implementado y ninguna fase previa lo reclama** — esta fase decide qué se construye y con qué datos reales.
- Áreas ya conectadas: autenticación (login, registro, verificación, recuperación, reset), invitaciones de empleados, servicios, empleados, horarios, pausas, licencias, reservas del panel con su ciclo de vida completo (confirmar, cancelar, completar, ausencia, reprogramar), página pública de negocio, reserva pública y "mis reservas" del cliente.
- Sin UI (aunque el backend existe o el dominio lo requiere): notificaciones en base de datos, ajustes del negocio, perfil/cuenta del usuario, gestión de tokens de API.
- Listado/descubrimiento de negocios: **ni backend ni frontend existen todavía** (a diferencia de los ítems anteriores) — lo cierra la Fase 10.5.
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

Mailpit es herramienta local de desarrollo/demo y no debe exponerse públicamente: no diseñar la web pública como si lo estuviera. Mantener separado lo que es tooling local de lo que es funcionalidad de la demo pública; la exposición real de tooling operativo la decide el workflow externo de operaciones.

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

### Fase 12 — Release readiness y handoff operativo

**Objetivo:** dejar un repositorio verificado y listo para release, con un contrato de runtime y de handoff de despliegue explícito, manteniendo el despliegue físico y las operaciones de host (Linux, Cloudflare, backups) fuera del workflow de desarrollo de la aplicación.

#### Frontera de responsabilidad

El despliegue real ocurre después, mediante un workflow externo y dedicado de operaciones de home server que corre sobre la máquina Linux real (paquete operativo `home_server_ops_claude`). Ese servidor es intencionalmente multiproyecto (`/srv/apps/`, `/srv/backups/`, Docker Engine y `cloudflared` compartidos): ReservaHub es solo uno de sus proyectos.

```text
REPOSITORIO RESERVAHUB              EXTERNO: OPERACIONES DE HOME SERVER
──────────────────────────          ──────────────────────────────────
aplicación Laravel/Inertia          descubrimiento del Linux real
límites Docker de la app            /srv/apps y /srv/backups
requisitos PostgreSQL               registro central de puertos
requisitos Redis                    adaptación del compose de producción
requisitos cola/scheduler           secretos de producción
tests                               volúmenes reales de PostgreSQL/Redis
CI                                  cloudflared compartido
build                               hostname de Cloudflare
contrato de entorno                 firewall del host
migraciones                         backups y restore
demo/bootstrap seguro               reinicio y recuperación
health checks         ── clone →    despliegue y rollback
documentación de runtime            operación multiproyecto
handoff de despliegue
```

Ese agente externo hace su propio descubrimiento (distro, CPU/RAM/discos, estado de Docker, puertos ocupados, estado del tunnel, repo clonado) y recién después decide y ejecuta la configuración productiva real. **Esta fase no adivina esas decisiones ni las documenta como si fueran fijas.**

**Fuera del alcance de este repositorio** (lo hace el workflow externo): detectar la distro Linux, inspeccionar hardware, crear `/srv/apps` y `/srv/backups`, elegir la ubicación física de los datos persistentes, instalar/configurar Docker en el host, asignar el puerto de loopback y mantener el registro central de puertos, instalar y configurar `cloudflared`, crear o modificar el tunnel compartido, configurar el hostname DNS de Cloudflare, manejar tokens del tunnel, configurar Cloudflare Access, firewall del host y exposición del router, clonar el repo en el servidor, crear el `.env` de producción y elegir el mecanismo de secretos, escribir el compose de producción específico del host, ejecutar el deploy y las migraciones reales, configurar backups/restore/reboot y unidades `systemd`, elegir el transporte de despliegue (runner self-hosted, SSH, Cloudflare Access) y hacer rollback en el host.

**No pre-construir** en este repo: `compose.prod.yaml`, overrides específicos del host, configuración de Cloudflare, unidades systemd, scripts de firewall, cron de backups, `.env` de producción, archivos/tokens de tunnel, scripts que provisionen `/srv`, ni puertos de producción adivinados. La regla es: *hacer que ReservaHub sea fácil de desplegar después, sin ejecutar ese despliegue ahora.* Lo que ya existe y es portable (el `compose.yaml` de desarrollo basado en Sail, `.env.example`, seeders) se preserva: es insumo del agente externo, no lastre.

**Aclaración sobre el frontend:** este proyecto no tiene frontend separado ni proceso Node en runtime. Usa Inertia + React compilado por `laravel-vite-plugin` (`pnpm build` → `public/build`) y servido por el mismo contenedor de la app. No crear un runtime React/Next independiente.

#### 12.1 Verificación final de la aplicación

Todo se ejecuta dentro de los contenedores (ver `CLAUDE.md`):

1. Suite completa: `docker compose exec laravel.test php artisan test`.
2. Concurrencia: `tests/Feature/Bookings/BookingConcurrencyTest.php` en verde (dos solicitudes al mismo slot, una sola gana).
3. Aislamiento y autorización: `tests/Feature/Tenancy/*`, `tests/Feature/Policies/*`, `tests/Unit/Policies/*`.
4. API: `tests/Feature/Api/*` (envelope, auth, disponibilidad, reservas).
5. Notificaciones y scheduler: `tests/Feature/Notifications/*` (incluye no duplicar recordatorios) y `php artisan schedule:list`.
6. Cola: worker consumiendo de Redis; verificar que los mails encolados salen (Mailpit en desarrollo).
7. Formato: `vendor/bin/pint --test`.
8. Build de frontend: `pnpm install --frozen-lockfile && pnpm build` sin errores y con `public/build` generado.
9. Dependencias: `composer validate --strict`, `composer audit`, lockfile de pnpm congelado (`--frozen-lockfile`), `pnpm audit`.
10. Docker: `docker compose config -q` sobre el `compose.yaml` del repo.
11. Smoke de aplicación: `/up` (health de Laravel), login web, y el flujo de API `login → availability → booking`.
12. Cuando existan las Fases 9 y 10: tests de pagos/webhooks idempotentes y de reconciliación, y del canal privado de broadcasting. **No inventar checks para tecnologías que el repo todavía no usa.**

#### 12.2 CI y readiness de release

Ver la sección **9. CI/CD**, sincronizada con el proyecto real (PHP 8.5, Node 24, pnpm, PostgreSQL 18, Redis). CI valida el repositorio en GitHub: tests, Pint, build de frontend, validación de dependencias y del compose. **CI no debe requerir acceso entrante al servidor privado ni administrar el host**; cualquier automatización futura de despliegue la elige el workflow externo de operaciones después de inspeccionar la máquina real.

#### 12.3 Contrato de runtime

Documentar de forma explícita (destino: `docs/DEPLOYMENT_HANDOFF.md`):

- Procesos/servicios de la aplicación (app HTTP, worker de cola, scheduler) y su entrypoint HTTP.
- Requisitos de PostgreSQL y de Redis, y para qué se usa cada uno.
- Requisitos de cola y de tareas programadas.
- Nombres de variables de entorno, quién es dueño de cada una y cuáles son secretas (**nunca valores reales de producción**).
- Procedimiento de build, de migración y de bootstrap/seed seguro.
- Health y smoke checks, logs, datos persistentes y necesidades de storage.
- Qué datos hay que respaldar y qué no debe exponerse públicamente jamás.

#### 12.4 Handoff de despliegue

Mantener `docs/DEPLOYMENT_HANDOFF.md` como contrato de aplicación —no como manual de administración de Linux—: qué necesita saber el agente externo sobre ReservaHub (proyecto Docker aislado, persistencia de PostgreSQL y de storage, si Redis necesita persistencia, cola y scheduler, comando de migración, bootstrap/demo seguro, smoke tests, señales de salud, información relevante para rollback, qué respaldar, exposiciones prohibidas) sin prescribirle lo que debe descubrir por su cuenta.

#### 12.5 Preparación de demo y release

- `DemoSeeder` determinista e idempotente, sin datos reales de clientes ni de pagos.
- Credenciales de demo documentadas como credenciales de demo, con contraseña rotable desde el entorno; `DatabaseSeeder` no debe usarse en producción (crea un usuario de prueba).
- README propio del proyecto (hoy sigue siendo el README stock de Laravel): qué es, stack, cómo levantarlo, cómo correr tests, enlaces a `docs/api.md` y `docs/DEPLOYMENT_HANDOFF.md`.
- Documentación de API vigente (`docs/api.md` + OpenAPI de Scramble, que solo se expone en local).
- Capturas y material de demo listos.
- Repositorio limpio (sin archivos generados ni secretos versionados).
- Tag `v1.0.0` solo cuando se cumplan sus criterios reales: Fases 0–11 completas, suite verde, README y documentación al día y handoff publicado.

## 8. Tests imprescindibles

### Unitarios

- Cálculo de duración.
- Cálculo de slots.
- Regla de cancelación.
- Conversión de zona horaria.

### Feature

- Owner crea servicio.
- Employee no modifica otra empresa.
- Cliente crea reserva válida.
- Reserva fuera de horario falla.
- Reserva solapada falla.
- Reserva durante licencia falla.
- Cancelación tardía falla.
- Webhook repetido no duplica pago.
- Recordatorio se envía una sola vez.

### Concurrencia

Simular dos solicitudes para el mismo turno y verificar que solamente una se confirme.

## 9. CI/CD

CI valida el repositorio en GitHub. **No administra el servidor privado ni necesita acceso entrante a él**: el despliegue real lo decide y ejecuta el workflow externo de operaciones de home server (ver Fase 12).

Versiones reales del proyecto, verificadas contra el repo y la imagen de Sail:

| Pieza | Versión real | Dónde se define |
|---|---|---|
| PHP | 8.5 en runtime (`composer.json` exige `^8.3`) | imagen `sail-8.5/app` |
| Laravel | 13.x | `composer.json` |
| Node | 24.x | imagen de Sail |
| Gestor de paquetes JS | **pnpm** (11.x); no hay `package-lock.json` | `pnpm-lock.yaml` |
| Base de datos | PostgreSQL 18 | `compose.yaml`, `.env.example` (`DB_CONNECTION=pgsql`) |
| Redis | `redis:alpine`, solo para la cola en runtime | `compose.yaml`, `QUEUE_CONNECTION=redis` |
| Tests | PHPUnit 12; `phpunit.xml` fuerza `DB_DATABASE=testing`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `MAIL_MAILER=array` | `phpunit.xml` |

Como la suite corre con `QUEUE_CONNECTION=sync` y `CACHE_STORE=array`, **CI necesita PostgreSQL pero no Redis**. Sí necesita el build de frontend: sin `public/build`, las páginas Inertia fallan y la suite reporta errores falsos (`Not a valid Inertia response`).

Workflow mínimo:

```yaml
name: ci

on:
  push:
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:18-alpine
        env:
          POSTGRES_DB: testing
          POSTGRES_USER: sail
          POSTGRES_PASSWORD: password
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U sail -d testing"
          --health-interval 10s --health-timeout 5s --health-retries 5
    env:
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_DATABASE: testing
      DB_USERNAME: sail
      DB_PASSWORD: password
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pdo_pgsql, redis
          coverage: none
      - run: composer validate --strict
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env
      - run: php artisan key:generate
      - uses: pnpm/action-setup@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '24'
          cache: pnpm
      - run: pnpm install --frozen-lockfile
      - run: pnpm build
      - run: php artisan test
      - run: vendor/bin/pint --test
      - run: docker compose config -q
        env:
          WWWUSER: '1000'
          WWWGROUP: '1000'
```

`composer audit` / `pnpm audit` pueden agregarse como job aparte (no bloqueante) para validación de dependencias.

## 10. Datos demo

Crear seeders para:

- Una empresa demo.
- Un owner.
- Dos empleados.
- Cinco servicios.
- Horarios semanales.
- Clientes.
- Reservas futuras y pasadas.

Usuario sugerido:

```text
owner@reservahub.test
password
```

Implementado parcialmente en `database/seeders/DemoSeeder.php`: siembra empresa, owner, dos empleados, cinco servicios y horarios semanales; **faltan clientes y reservas futuras/pasadas**. Es idempotente (no re-siembra si ya existe `peluqueria-demo`) y no contiene datos reales de clientes ni de pagos. `DatabaseSeeder` además crea un usuario `test@example.com` de conveniencia: **en un entorno público hay que sembrar con `db:seed --class=DemoSeeder`, no con el seeder por defecto**, y la contraseña de demo debe poder rotarse desde el entorno. Nunca correr `migrate:fresh --seed` sobre datos que deban persistir.

## 11. Capturas recomendadas

Tomarlas **después** del rediseño de la Fase 11; capturar el frontend actual sería material de portfolio engañoso.

- Landing pública / aviso de demo.
- Login.
- Dashboard.
- Calendario.
- Formulario de servicio.
- Selector de disponibilidad.
- Reserva confirmada.
- Panel de colas.
- Resultado de tests.

## 12. Mejoras opcionales

- Suscripciones por plan.
- Cupones.
- Lista de espera.
- Reservas recurrentes.
- Integración con Google Calendar.
- Exportación iCalendar.
- Facturación.
- Aplicación móvil consumiendo API.
- Métricas con observabilidad.

## 13. Entregables para GitHub

- Código.
- README propio del proyecto (reemplazar el README stock de Laravel).
- Diagrama ER.
- Documentación de API: `docs/api.md` + OpenAPI (Scramble, expuesto solo en local). Colección Postman opcional.
- `compose.yaml` de la aplicación (desarrollo, basado en Sail). El compose de producción específico del host lo escribe el workflow externo de operaciones.
- Workflow GitHub Actions de validación (tests, Pint, build, dependencias). Sin jobs que administren el servidor privado.
- `docs/DEPLOYMENT_HANDOFF.md`: contrato de runtime y handoff para el agente externo de operaciones.
- Documentación de la Fase 11: decisiones de diseño frontend, guía de uso de la demo, workflow multi-rol y flujo local de email.
- Capturas (posteriores al rediseño de la Fase 11).
- Release `v1.0.0` cuando se cumplan los criterios de la Fase 12.
- Issues cerrados que documenten el proceso.
