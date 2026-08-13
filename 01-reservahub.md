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
- Logo.
- Estado activo o suspendido.

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
- Pausas.
- Feriados.
- Licencias.
- Bloqueos manuales.
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
- Opcional: WhatsApp mediante proveedor de prueba o adaptador simulado.

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
POST   /api/payments
POST   /api/webhooks/payments/{provider}
```

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
- Toda consulta debe filtrar por empresa.

## 7. Implementación por fases

### Estado actual (verificado contra el código y los tests)

| Fase | Estado | Evidencia |
|---|---|---|
| 0 — Preparación | Hecha, salvo el pipeline de CI | Proyecto Laravel 13 + Sail, `.env.example`, Pint, pnpm. **Falta `.github/workflows`** → se cierra en la Fase 10 |
| 1 — Autenticación | Hecha | `tests/Feature/Auth/*` |
| 2 — Empresas y tenancy | Hecha | `tests/Feature/Tenancy/*`, `tests/Feature/Policies/*`, `EnsureBusinessContext` |
| 3 — Servicios y empleados | Hecha | `tests/Feature/Dashboard/*`, `DemoSeeder` |
| 4 — Motor de disponibilidad | Hecha | `app/Services/AvailabilityService.php`, `tests/Unit/Services/AvailabilityServiceTest.php` |
| 5 — Reservas | Hecha | `app/Actions/Bookings/*`, `tests/Feature/Bookings/*` (incluye concurrencia) |
| 6 — Notificaciones y scheduler | Hecha | `app/Notifications/Bookings/*`, `SendBookingReminders`, contenedores `queue` y `scheduler` |
| 7 — API y Sanctum | Hecha | `routes/api.php`, `tests/Feature/Api/*`, `docs/api.md` + OpenAPI |
| 8 — Pagos | Pendiente | No existen `payments`/`webhook_events` ni `Services/Payments` |
| 9 — Tiempo real | Pendiente | Sin Reverb; `BROADCAST_CONNECTION=log` |
| 10 — Release readiness y handoff | En curso | `docs/DEPLOYMENT_HANDOFF.md` escrito. Pendientes: workflow de CI, README propio, seeder de demo con clientes y reservas, proxies de confianza para operar detrás de un proxy/tunnel |

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

### Fase 8 — Pagos

1. Crear contrato `PaymentGateway`.
2. Crear implementación fake.
3. Crear implementación real opcional.
4. Guardar eventos webhook.
5. Validar firma.
6. Implementar idempotencia.
7. Crear job de reconciliación.
8. Tests con payloads simulados.

### Fase 9 — Tiempo real

1. Instalar Reverb.
2. Crear evento de reserva.
3. Canal privado por empresa.
4. Autorizar canal.
5. Actualizar calendario en vivo.

### Fase 10 — Release readiness y handoff operativo

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

#### 10.1 Verificación final de la aplicación

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
12. Cuando existan las Fases 8 y 9: tests de pagos/webhooks idempotentes y de reconciliación, y del canal privado de broadcasting. **No inventar checks para tecnologías que el repo todavía no usa.**

#### 10.2 CI y readiness de release

Ver la sección **9. CI/CD**, sincronizada con el proyecto real (PHP 8.5, Node 24, pnpm, PostgreSQL 18, Redis). CI valida el repositorio en GitHub: tests, Pint, build de frontend, validación de dependencias y del compose. **CI no debe requerir acceso entrante al servidor privado ni administrar el host**; cualquier automatización futura de despliegue la elige el workflow externo de operaciones después de inspeccionar la máquina real.

#### 10.3 Contrato de runtime

Documentar de forma explícita (destino: `docs/DEPLOYMENT_HANDOFF.md`):

- Procesos/servicios de la aplicación (app HTTP, worker de cola, scheduler) y su entrypoint HTTP.
- Requisitos de PostgreSQL y de Redis, y para qué se usa cada uno.
- Requisitos de cola y de tareas programadas.
- Nombres de variables de entorno, quién es dueño de cada una y cuáles son secretas (**nunca valores reales de producción**).
- Procedimiento de build, de migración y de bootstrap/seed seguro.
- Health y smoke checks, logs, datos persistentes y necesidades de storage.
- Qué datos hay que respaldar y qué no debe exponerse públicamente jamás.

#### 10.4 Handoff de despliegue

Mantener `docs/DEPLOYMENT_HANDOFF.md` como contrato de aplicación —no como manual de administración de Linux—: qué necesita saber el agente externo sobre ReservaHub (proyecto Docker aislado, persistencia de PostgreSQL y de storage, si Redis necesita persistencia, cola y scheduler, comando de migración, bootstrap/demo seguro, smoke tests, señales de salud, información relevante para rollback, qué respaldar, exposiciones prohibidas) sin prescribirle lo que debe descubrir por su cuenta.

#### 10.5 Preparación de demo y release

- `DemoSeeder` determinista e idempotente, sin datos reales de clientes ni de pagos.
- Credenciales de demo documentadas como credenciales de demo, con contraseña rotable desde el entorno; `DatabaseSeeder` no debe usarse en producción (crea un usuario de prueba).
- README propio del proyecto (hoy sigue siendo el README stock de Laravel): qué es, stack, cómo levantarlo, cómo correr tests, enlaces a `docs/api.md` y `docs/DEPLOYMENT_HANDOFF.md`.
- Documentación de API vigente (`docs/api.md` + OpenAPI de Scramble, que solo se expone en local).
- Capturas y material de demo listos.
- Repositorio limpio (sin archivos generados ni secretos versionados).
- Tag `v1.0.0` solo cuando se cumplan sus criterios reales: Fases 0–9 completas, suite verde, README y documentación al día y handoff publicado.

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

CI valida el repositorio en GitHub. **No administra el servidor privado ni necesita acceso entrante a él**: el despliegue real lo decide y ejecuta el workflow externo de operaciones de home server (ver Fase 10).

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
- Capturas.
- Release `v1.0.0` cuando se cumplan los criterios de la Fase 10.
- Issues cerrados que documenten el proceso.
