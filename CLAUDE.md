# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

Fases 0–10 are implemented (auth, tenancy, services/employees, availability, bookings, notifications/scheduler, REST API + Sanctum, account/business management, payments, realtime/Reverb — see `docs/superpowers/plans/` and the status table in `01-reservahub.md` §7). Fase 11 (frontend redesign) and Fase 12 (release readiness + handoff) are not started. `01-reservahub.md` is still the authoritative spec for anything not yet implemented.

The frontend is deliberately minimal for now (17 Inertia pages, 4 shared components, Tailwind 4 with no component library, a placeholder dashboard). **Fase 11 owns the redesign** and must start from `superpowers:brainstorming` plus the installed frontend-design skill — not from UI code.

Standard Laravel commands apply: `php artisan test` (or a specific test with `php artisan test --filter=TestName`), `vendor/bin/pint --test` for formatting. For the frontend, see **Package manager** below before running any JS command.

## Responsibility boundary: this repo does not operate the production server

**Do not perform Linux host, Cloudflare, or production deployment work from this repository**, and do not add roadmap tasks that assign it here. Production runs on a multiproject home server (`/srv/apps`, `/srv/backups`, shared Docker Engine and `cloudflared`) operated by a separate, dedicated home-server operations workflow that runs on the real Linux machine and discovers the host state itself.

Out of scope here: host provisioning, `/srv` layout, host port registry and loopback binding, `cloudflared` / tunnel / DNS / Cloudflare Access, host firewall, production `.env` and secrets, the host-specific production Compose file, running real migrations on the server, backups/restore/reboot, systemd units, deployment transport, and production rollback.

In scope here: the application, its development Docker boundaries, tests, CI that validates the repo on GitHub (never reaching into the private server), builds, the environment contract, migrations, safe demo/bootstrap data, health checks, and the deployment handoff in `docs/DEPLOYMENT_HANDOFF.md` — the document the external operations workflow consumes. Keep that file in sync when runtime requirements change (new service, new env var, new persistent path, new exposure rule). Do not create speculative production artifacts (`compose.prod.yaml`, systemd units, tunnel config, backup cron, guessed production ports).

## Development environment: Docker (Laravel Sail)

The project runs under Docker via Laravel Sail — `compose.yaml` at the repo root defines `laravel.test` (app), `pgsql`, `redis`, `mailpit`, `queue` (`php artisan queue:work`), `scheduler` (`php artisan schedule:work`), and `reverb` (`php artisan reverb:start`). There is no working native (non-Docker) path: `.env`'s `DB_HOST`/`REDIS_HOST`/`MAIL_HOST` point at Docker service names (`pgsql`, `redis`, `mailpit`), which only resolve inside the `laravel.test` container's network. Always develop and test against the containers, not `php artisan serve` on the host.

Queued jobs run on Redis (`QUEUE_CONNECTION=redis`), not the `database` driver — the `queue` container is the only consumer. Outgoing mail (booking notifications, reminders, employee invitations) is caught by Mailpit rather than actually sent; inspect it at the dashboard port from **Testing a feature branch or worktree** below (`http://localhost:8025` on the main checkout). `queue:work` boots once and keeps application code in memory, so after editing a listener or a notification class, restart the worker to pick up the change:

```bash
docker compose restart queue
```

`reverb` corre `php artisan reverb:start` y, igual que `queue:work`, mantiene el
código en memoria: después de editar un evento, un listener o el archivo de
canales hay que reiniciarlo.

```bash
docker compose restart reverb
```

**`vendor/bin/sail` does not work in Git Bash on Windows** — it hard-refuses with `Unsupported operating system [MINGW64_NT-...]`. Use `docker compose` directly instead, and pass dummy `WWWUSER`/`WWWGROUP` values (Sail's wrapper normally injects these; without it you'll get harmless "variable not set" warnings but the containers still run fine):

```bash
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
docker compose exec laravel.test php artisan migrate:fresh --force
docker compose exec laravel.test php artisan test
docker compose exec laravel.test vendor/bin/pint --test
```

**Testing a feature branch or worktree in parallel with the main checkout:** `docker compose`'s default project name is derived from the current directory's basename, so running `docker compose up -d` from a git worktree at a different path (e.g. `.claude/worktrees/<branch-name>/`) automatically gets its own project name, containers, network, and volumes — it won't collide with a stack already running from the main checkout. It *will* collide on **host ports** though (both stacks default to 80, 5432, 6379, 1025, 8025, 5173), so before bringing up a second stack, add distinct forwarded ports to that worktree's `.env` (values must stay ≤ 65535):

```
APP_URL=http://localhost:8180
APP_PORT=8180
FORWARD_DB_PORT=54320
FORWARD_REDIS_PORT=63790
FORWARD_MAILPIT_PORT=10250
FORWARD_MAILPIT_DASHBOARD_PORT=8026
VITE_PORT=5273
FORWARD_REVERB_PORT=8081
VITE_REVERB_PORT=8081
```
`VITE_REVERB_PORT` has to match `FORWARD_REVERB_PORT` — it gets compiled into the frontend bundle, so changing it requires re-running `pnpm build`.

Also set `DB_HOST=pgsql` in that `.env` (not `127.0.0.1` — that only works for a *native* host process talking to a container's forwarded port, and there is no working native path here per above). Mailpit's dashboard (to inspect sent verification/reset emails) is then at `http://localhost:<FORWARD_MAILPIT_DASHBOARD_PORT>`.

**Bootstrapping a fresh worktree:** a new worktree starts with no `.env`, `vendor/`, `node_modules/` or `public/build` — all four are gitignored, so `git worktree add` does not bring them over. Two of those absences bite in non-obvious ways:

- Without `vendor/`, `docker compose` cannot even *build*: `laravel.test`'s build context is `./vendor/laravel/sail/runtimes/8.5`, which doesn't exist yet. Chicken-and-egg. Break it by running Composer in the already-built Sail image from the main checkout. `MSYS_NO_PATHCONV=1` is required in Git Bash — otherwise it rewrites `/var/www/html` into `C:/Program Files/Git/var/www/html` and Docker rejects it.
- Without `public/build`, Laravel's `@vite` can't resolve its manifest and **every Inertia page fails to render**. The suite then reports ~28 failures reading `Not a valid Inertia response`, which look like application bugs and are not. Always run the frontend build before trusting a baseline test run in a new worktree.

Full sequence, from the worktree's own directory:

```bash
cp ../../../.env .env          # then edit the forwarded ports per the block above
MSYS_NO_PATHCONV=1 docker run --rm -u root \
  -v "$(pwd -W):/var/www/html" -w /var/www/html \
  --entrypoint composer sail-8.5/app:latest install --no-interaction
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
docker compose exec laravel.test php artisan migrate:fresh --force
docker compose exec laravel.test bash -lc "pnpm install --frozen-lockfile && rm -f public/hot && pnpm build"
docker compose exec laravel.test php artisan test
```

The first `php artisan test` after `up -d` takes roughly ten times longer than the ones after it (~600 s vs ~70 s) — Postgres cold start plus a cold opcache, not a hung suite.

**Running the frontend build in a container:** if the JS dev server (`pnpm dev`) was ever run natively on the host against this same working directory, it writes `public/hot`, which makes Laravel's `@vite` directive emit script tags pointing at that (now-dead) native Vite server instead of the built assets — resulting in a blank page with a console `@vitejs/plugin-react can't detect preamble` error. Delete `public/hot` (`rm -f public/hot`) and run `pnpm build` if you hit this.

**Tearing down a worktree's stack:** `docker compose down -v` (from that worktree's directory) before or when the worktree itself is removed. The `superpowers:finishing-a-development-branch` skill's cleanup step only runs `git worktree remove` — it has no knowledge of Docker, so a Sail stack brought up for manual testing in a worktree is never torn down automatically and will keep running (and holding its forwarded ports) after the branch is merged unless done by hand.

## Package manager: pnpm, not npm

This project uses **pnpm**, not npm — there is no `package-lock.json`, only `pnpm-lock.yaml`. Always use `pnpm install` / `pnpm dev` / `pnpm build`, never the `npm` equivalents.

## API REST (Fase 7)

REST bajo `/api`, sin versión, autenticada con tokens Sanctum (`POST /api/auth/login` con `email`, `password`, `device_name`). Sin abilities: autoriza el rol vía Policies.

El negocio se resuelve de dos formas: staff → middleware `business` (`EnsureBusinessContext`) sobre el usuario del token; cliente → `/api/businesses/{slug}/...` con `BindPublicBusiness`. Las rutas de reservas (`GET|PATCH /api/bookings*`, `cancel`) son compartidas y **no** llevan el middleware `business`: el trait `App\Http\Controllers\Api\Concerns\ResolvesBookingScope` liga el negocio si el usuario es staff, o levanta `BusinessScope` si es customer.

Toda respuesta pasa por `App\Support\ApiResponse` y tiene exactamente `{success, data, message, errors}`; las excepciones se mapean a ese mismo envelope en `bootstrap/app.php`. Los listados paginados van en `data.items` + `data.meta`.

Documentación: `docs/api.md` y OpenAPI en `/docs/api` (dedoc/scramble, solo en local).

## Revocación de acceso y moneda (Fase 8)

Toda revocación de acceso pasa por `App\Support\UserAccessRevoker::revoke($user, $keepSessionId)`:
rota el `remember_token`, borra todos los tokens de Sanctum y borra las filas de
`sessions` del usuario. **Falla cerrado**: lanza `UnsupportedSessionDriverException`
si `SESSION_DRIVER` no es `database`, porque con otro driver las sesiones web no
se pueden invalidar. No usar `Auth::logoutOtherDevices()` — `AuthenticateSession`
no está en el grupo `web`, así que no invalidaría nada.

Lo consumen el cambio de contraseña (`App\Actions\Account\ChangePassword`, que
preserva la sesión actual en web y no preserva nada por API) y la desactivación
de usuarios (`App\Actions\Users\SetUserActiveStatus`).

Las monedas válidas son el enum `App\Enums\Currency` (set acotado de códigos
ISO-4217, sin dependencia externa). La columna `businesses.currency` sigue siendo
string: el enum se usa para validar y para poblar el formulario.

## Pagos (Fase 9)

Proveedor único **simulado** (`App\Services\Payments\Simulated\SimulatedPaymentGateway`), ligado a
`PaymentGateway` en `AppServiceProvider`. No hay variable de selección de proveedor: un adapter real
futuro reemplaza ese binding. El estado del proveedor vive en `simulated_provider_payments` y es
**independiente** de `payments`: la reconciliación compara dos almacenes de verdad distintos.

Dos reglas que no se pueden romper:

1. **`App\Actions\Payments\ApplyPaymentResult` es el único camino** que aplica un resultado del
   proveedor, y **`ConfirmBooking` el único** que confirma una reserva. El webhook y
   `payments:reconcile` convergen ahí; ningún controller ni comando muta `Booking` por su cuenta.
2. **`App\Services\Payments\ProcessPaymentWebhook` es el único borde** de procesamiento: lo usan el
   endpoint HTTP y `DeliverSimulatedProviderWebhook` (que entrega **en proceso**, sin HTTP ni DNS).

Idempotencia: identidad del evento por `unique (provider, external_event_id)`, claim con estado
(`received|processed|ignored|failed`) bajo `for update`, y efecto + marca de completado en la misma
transacción. `received` y `failed` son reprocesables a propósito: un fallo transitorio no puede volver
un evento imposible de procesar.

Orden de bloqueo global: **`webhook_events` → `bookings` → `payments`**.

La expiración pertenece a la reserva (`bookings.payment_expires_at`), no al pago:
`bookings:expire-unpaid` cancela vía `CancelBooking` con `CancellationReason::PaymentWindowExpired`
(actor nulo, sin el corte de `cancellation_hours`), y **nunca** cancela mientras haya un intento
`pending` sin resolver. Confirmación por pago: `ConfirmationReason::PaymentApproved` + el pago; el
actor nulo por sí solo no significa nada.

Los montos y la moneda son autoridad **local** (`payments.amount`/`currency`, snapshot de la reserva y
del negocio); un webhook con otro importe se registra como `ignored/amount_mismatch` y no cambia nada.
Los payloads se persisten con lista blanca (`WebhookPayloadRedactor`) y los headers y firmas nunca se
guardan ni se loguean.

## Tiempo real (Fase 10)

Seis transiciones de reserva (`created`, `confirmed`, `cancelled`,
`rescheduled`, `completed`, `no_show`) disparan eventos de dominio planos.
`App\Listeners\BroadcastBookingChange` — un tipo unión, registrado por
autodescubrimiento — los traduce al **único** evento de broadcast,
`App\Events\Broadcasting\BookingChanged`.

Tres reglas que no se pueden romper:

1. **`BookingChanged` es el único evento de broadcast.** No hay eventos de
   WebSocket de pagos: un pago aprobado llega a la pantalla por
   `ApplyPaymentResult` → `ConfirmBooking` → `BookingConfirmed`, como cualquier
   confirmación. Un test falla si aparece otra clase bajo
   `app/Events/Broadcasting/`.
2. **`ShouldDispatchAfterCommit` no es decorativo.** `ConfirmBooking` dispara
   dentro de la transacción de `ApplyPaymentResult` y `CancelBooking` dentro de
   la de `ExpireUnpaidBookings`. Sin eso, un rollback dejaría al navegador con
   un cambio que nunca ocurrió.
3. **El payload es una pista, no datos:** `{booking_id, change, updated_at}`. El
   cliente recarga el estado canónico con `router.reload({ only: ['bookings'] })`,
   así que las Policies siguen siendo la autoridad. `businessId` enruta el canal
   y no viaja en el payload — por eso `broadcastWith()` es explícito.

Canal único: `private-business.{businessId}`, autorizado en `routes/channels.php`
con la unión exacta de lo que ya exigen `EnsureBusinessContext` y
`BookingPolicy::viewAny` (rol de staff, usuario activo, negocio propio, negocio
activo). El identificador se compara como string para que `'05'` o `'5abc'` no
entren a un canal ajeno. El cliente no se suscribe a nada.

`phpunit.xml` deja `BROADCAST_CONNECTION=null` a propósito: con
`QUEUE_CONNECTION=sync`, un driver real haría que cada test que toca una reserva
llamara a un Reverb inexistente. `ChannelAuthorizationTest` activa el driver real
solo en su `setUp()`, porque `NullBroadcaster::auth()` autoriza a cualquiera sin
mirar `routes/channels.php`.

### Smoke de dos navegadores

```bash
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan db:seed --class=DemoSeeder
docker compose exec laravel.test bash -lc "pnpm install --frozen-lockfile && rm -f public/hot && pnpm build"
docker compose ps reverb        # Up
```

1. **Navegador A** — iniciar sesión como `owner@reservahub.test` y abrir
   `/dashboard/bookings`. En DevTools → Network → WS tiene que haber **una**
   conexión a `localhost:<FORWARD_REVERB_PORT>` en estado `101 Switching
   Protocols`, y entre sus mensajes un `pusher:subscription_succeeded` para
   `private-business.<id del negocio A>`.
2. **Navegador B** (ventana privada) — iniciar sesión como cliente y reservar
   desde la página pública del negocio.
3. **En A:** la fila nueva aparece sola, sin refresh manual.
4. **En B:** pagar la seña por el checkout simulado y aprobarla.
5. **En A:** la fila pasa de `Pendiente` a `Confirmada` sola.
6. Con dos pestañas de staff abiertas, pulsar `Completar` en una: la otra se
   actualiza sola.
7. **Aislamiento entre negocios.** Un tercer navegador con sesión de staff de
   **otro** negocio, abierto en `/dashboard/bookings`. En su DevTools → Network
   → WS, la conexión tiene que mostrar `pusher:subscription_succeeded` **solo**
   para `private-business.<id del negocio B>`, sin ninguna suscripción al canal
   de A y sin ningún frame `booking.changed` durante los pasos 2 a 6. La
   ausencia de cambios en la tabla es la consecuencia; la prueba es que el canal
   de A no aparece en la conexión.

### Smoke de fallo de Reverb

Demuestra que la corrección del dominio no depende del tiempo real.

1. `docker compose stop reverb`
2. En el navegador A, ejecutar una transición (por ejemplo `Confirmar`).
3. La acción HTTP tiene éxito con normalidad: sin error, sin timeout, sin 500.
4. Verificar el estado comprometido en PostgreSQL:
   ```bash
   docker compose exec laravel.test php artisan tinker --execute="echo \App\Models\Booking::withoutGlobalScopes()->find(<id>)->status->value;"
   ```
5. Un refresh manual muestra el estado canónico.
6. `docker compose start reverb` — las transiciones siguientes vuelven a
   actualizar la pantalla sola.

Puede quedar una fila en `failed_jobs` con `BroadcastEvent`: el worker corre con
`--tries=3`, así que tras tres intentos fallidos el job se registra ahí. Es el
resultado esperado y no afecta al dominio.

## Localization: `APP_LOCALE=es`

The app's default locale is Spanish (`config/app.php` → `env('APP_LOCALE', 'es')`, `.env.example` sets `APP_LOCALE=es`, `APP_FALLBACK_LOCALE=en`). Laravel's built-in validation/auth/passwords/pagination strings are translated via `lang/es/` (published with `laravel-lang/lang`, `php artisan lang:add es`) — added in Fase 3 after mixed English/Spanish validation errors surfaced (custom messages were already hardcoded in Spanish; Laravel's default rule messages weren't). Custom validation messages (`ValidationException::withMessages([...])`, Form Request `messages()`) must be written in Spanish directly, same as before — only Laravel's own built-in strings needed the `lang/` files. If a new Laravel version adds rules/strings not yet in `lang/es/validation.php`, re-run `php artisan lang:add es` to refresh it.

## What this is

ReservaHub is a SaaS booking/appointment system (in Spanish) for businesses that work by time slots — hair salons, gyms, workshops, tutors, studios, etc. It's a learning/demo project meant to showcase a complete Laravel build: MVC, auth, roles/permissions, simple multi-tenancy, availability rules, overlap prevention, a REST API, payments + webhooks, queues, notifications, scheduled tasks, real-time updates (Reverb), tests, Docker, and CI/CD.

`01-reservahub.md` is the authoritative spec — read it in full before implementing a feature area; the summary below only hits the parts most load-bearing for architecture decisions.

## Core domain architecture

**Multi-tenancy**: every tenant-owned table carries `business_id`. Every query must filter by the current business — there is no shared/global data across businesses except the `users` table pattern (a user's `business_id` is nullable, e.g. for platform-level accounts). Policies must prevent cross-business access; this is a primary test target ("employee no modifica otra empresa").

**Roles**: `owner`, `admin`, `employee`, `customer`. Spec suggests starting with a simple `role` column on `users` and migrating to granular permissions later — don't over-engineer permissions up front.

**Booking domain**: `bookings` reference `business_id`, `customer_id`, `employee_id`, `service_id`, `starts_at`/`ends_at`, and a `status` enum: `pending`, `confirmed`, `cancelled`, `completed`, `no_show`. Duration always comes from the `service`, never from client input.

**Availability engine** (Fase 4) is the core algorithm: given date + service + employee, it must combine the employee's weekly `schedules`, subtract `schedule_breaks`, subtract `time_offs`, subtract existing overlapping `bookings`, and account for the service's `buffer_minutes`, all in the business's `timezone` — then return free slots. This logic should be a dedicated `Services/AvailabilityService.php`, unit-tested independent of HTTP.

**Concurrency / overlap safety**: booking creation must re-validate availability *inside* a DB transaction (not just at the form-request layer) to prevent two simultaneous requests from double-booking the same employee/slot. This is explicitly called out as a required test scenario.

**Payments**: abstracted behind a `PaymentGateway` contract with a simulated implementation and an optional real one. Webhook handling must be idempotent — `webhook_events.external_event_id` is unique per provider, and duplicate webhook deliveries must not duplicate a payment or double-confirm a booking. A booking with a required deposit (`deposit_amount`) stays `pending` until the payment is confirmed via webhook.

**Notifications**: booking confirmation, reschedule, cancellation, 24h/2h reminders, and employee alerts, over email + database channels (WhatsApp optional/simulated). Reminders must not be sent twice — dedupe when building the scheduled command that queues them.

## Planned code layout (from spec, Fase-driven)

```
app/
├── Actions/Bookings/     # CreateBooking, CancelBooking, ConfirmBooking, RescheduleBooking — one class per use case
├── Events/
├── Exceptions/
├── Http/Controllers/{Web,Api}/
├── Http/Requests/        # validates input
├── Http/Resources/       # JSON output shaping
├── Jobs/
├── Listeners/
├── Models/
├── Notifications/
├── Policies/             # authorization, esp. cross-business access checks
├── Services/             # AvailabilityService, Payments/*
└── Support/
```

Layering intent: Controller coordinates request/response only; Form Request validates; Policy authorizes; Action executes the use case (e.g. booking creation runs inside a transaction, re-checks availability, persists, fires events); Service holds reusable/integration logic (availability calculation, payment gateway); Model holds relations/casts/scopes only.

## API conventions

REST API under `/api/*`, authenticated via Sanctum (Fase 7). Every response follows:

```json
{
  "success": true,
  "data": {},
  "message": "Reserva creada correctamente.",
  "errors": null
}
```

## Key business rules to preserve in any implementation

- An employee cannot have two overlapping bookings.
- Booking duration is derived from the service, never client-supplied.
- Bookings must fall within working hours and respect breaks, holidays, and time off.
- Customers cannot cancel past the business's configured `cancellation_hours`.
- A repeated webhook must never duplicate a payment.
- A booking with a required deposit stays `pending` until payment is confirmed.
- Every query must be scoped by `business_id`.

## Real-time (Fase 10)

Laravel Reverb, one private channel per business, authorized via channel auth, used to push live calendar updates on booking events.
