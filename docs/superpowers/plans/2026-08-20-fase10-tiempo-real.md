# Fase 10 — Tiempo real con Laravel Reverb: plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que `Dashboard/Bookings/Index` se actualice sola cuando una reserva cambia, empujada por Laravel Reverb sobre un canal privado por negocio, sin que el dominio dependa del WebSocket.

**Architecture:** Seis eventos de dominio de reserva convergen en un listener síncrono que despacha un único evento de broadcast `BookingChanged`, marcado `ShouldDispatchAfterCommit` para no adelantar estado no comprometido y `ShouldBroadcast` para viajar por la cola Redis que ya existe. El payload son tres escalares no sensibles; el navegador responde con una recarga parcial de Inertia, así que las Policies y el controlador siguen siendo la única autoridad sobre qué ve cada usuario.

**Tech Stack:** Laravel 13.8, `laravel/reverb` ^1.11, `pusher/pusher-php-server` ^7.2 (transitiva), Inertia 3.6 + React 19, `laravel-echo` 2.4 + `pusher-js` + `@laravel/echo-react` 2.4, PostgreSQL 18, Redis, Docker Compose (Sail), pnpm.

**Spec:** `docs/superpowers/specs/2026-08-20-fase10-tiempo-real-design.md`

## Global Constraints

- **Rama y worktree únicos:** `feat/phase-10-realtime` en `.claude/worktrees/feat-phase-10-realtime`. No crear otra rama ni otro worktree.
- **Gestor de paquetes JS: pnpm.** Nunca `npm`. No existe `package-lock.json`.
- **Todos los comandos corren dentro del contenedor**, desde el directorio del worktree: `docker compose exec -T laravel.test <cmd>`. `vendor/bin/sail` no funciona en Git Bash sobre Windows.
- **`BROADCAST_CONNECTION=null` se queda como valor global de `phpunit.xml`.** Solo `ChannelAuthorizationTest` configura el driver real, y solo dentro de su `setUp()`.
- **Ningún archivo de `app/Actions/Payments/`, `app/Services/Payments/` ni `app/Jobs/` se modifica.**
- **Ningún archivo bajo `app/Events/Broadcasting/` salvo `BookingChanged.php`.**
- **Sin infraestructura de tests de JavaScript.** Nada de Vitest, Jest, Playwright ni Cypress.
- **Fuera de alcance, en toda tarea:** tiempo real para el cliente, tiempo real en `Bookings/Show`, calendario, canales de presencia, seguimiento de conexiones, estado global de frontend, escalado de Reverb, dominio dedicado de tiempo real, configuración de Cloudflare, despliegue de producción.
- **Línea base a no romper:** 496 tests, 1457 assertions, 0 fallos.
- **Formato:** `vendor/bin/pint --test` limpio antes de cada commit.
- Los mensajes de commit se escriben en prosa normal (no en estilo comprimido) y terminan con `Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM`.

---

## Mapa de archivos

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `app/Events/BookingCompleted.php` | crear | Evento de dominio plano para la transición `completed` |
| `app/Events/BookingNoShow.php` | crear | Evento de dominio plano para la transición `no_show` |
| `app/Actions/Bookings/CompleteBooking.php` | modificar | Disparar `BookingCompleted` tras `fresh()` |
| `app/Actions/Bookings/MarkNoShow.php` | modificar | Disparar `BookingNoShow` tras `fresh()` |
| `app/Enums/BookingChange.php` | crear | Los seis valores del contrato de cable y el mapeo desde el evento de dominio |
| `app/Events/Broadcasting/BookingChanged.php` | crear | Único evento de broadcast: canal, nombre y payload |
| `app/Listeners/BroadcastBookingChange.php` | crear | Traducción dominio → transporte; única frontera |
| `config/broadcasting.php` | crear (publicar) | Conexiones de broadcasting del framework |
| `config/reverb.php` | crear (publicar) + editar | Servidor Reverb y `allowed_origins` desde entorno |
| `routes/channels.php` | crear | Autorización del canal privado de staff |
| `bootstrap/app.php` | modificar | Registrar `channels:` en `withRouting()` |
| `app/Http/Controllers/Dashboard/BookingController.php` | modificar | Pasar `businessId` como prop de página en `index()` |
| `resources/js/app.jsx` | modificar | `configureEcho({ broadcaster: 'reverb' })` |
| `resources/js/Components/BookingsRealtime.jsx` | crear | Único mecanismo de suscripción: escucha, coalesce y recarga |
| `resources/js/Pages/Dashboard/Bookings/Index.jsx` | modificar | Montar el suscriptor cuando hay configuración |
| `compose.yaml` | modificar | Servicio `reverb` |
| `.env.example` | modificar | Contrato de entorno `REVERB_*` / `VITE_REVERB_*` |
| `package.json` / `pnpm-lock.yaml` | modificar | `laravel-echo`, `pusher-js`, `@laravel/echo-react` |
| `composer.json` / `composer.lock` | modificar | `laravel/reverb` |
| `tests/Unit/Enums/BookingChangeTest.php` | crear | Mapeo exhaustivo evento → valor |
| `tests/Unit/Events/BookingChangedTest.php` | crear | Contrato exacto de cable |
| `tests/Feature/Bookings/BookingStatusTransitionsTest.php` | modificar | Los dos eventos de dominio nuevos |
| `tests/Feature/Realtime/BroadcastBookingChangeTest.php` | crear | Seis transiciones, after-commit, rollback |
| `tests/Feature/Realtime/ReverbConfigTest.php` | crear | Parseo de `REVERB_ALLOWED_ORIGINS` |
| `tests/Feature/Realtime/ChannelAuthorizationTest.php` | crear | Diez casos de autorización de canal |
| `tests/Feature/Realtime/PaymentRealtimeIntegrationTest.php` | crear | Fase 9 llega por la vía normal; sin clases de broadcast de pagos |
| `tests/Feature/Dashboard/BookingsTest.php` | modificar | Prop `businessId` presente |
| `docs/DEPLOYMENT_HANDOFF.md` | modificar | Reverb en el contrato de runtime |
| `CLAUDE.md` | modificar | Sección Fase 10, contenedor `reverb`, smokes |
| `01-reservahub.md` | modificar | Fila 10 de la tabla de estado |

---

### Task 1: Levantar el stack del worktree y fijar la línea base

El worktree está sin `vendor/`, `node_modules/`, `.env` ni `public/build`: los cuatro están en `.gitignore` y `git worktree add` no los trae. Sin `public/build`, **toda página Inertia falla** con `Not a valid Inertia response` y la suite reporta ~28 fallos que parecen bugs de aplicación y no lo son.

**Files:**
- Create (todos ignorados por git, ninguno se commitea): `.env`, `vendor/`, `node_modules/`, `public/build/`

**Interfaces:**
- Consumes: nada.
- Produces: un stack Docker propio del worktree, con puertos que no chocan con el checkout principal, y la línea base verde de 496 tests.

- [ ] **Step 1: Copiar el `.env` del checkout principal**

```bash
cp ../../../.env .env
```

- [ ] **Step 2: Fijar los puertos del worktree en `.env`**

Editar `.env` y dejar exactamente estos valores (los demás quedan como estaban). `FORWARD_REVERB_PORT` se usa recién en la Task 8, pero se fija ahora para no volver a tocar el archivo:

```ini
APP_URL=http://localhost:8180
APP_PORT=8180
FORWARD_DB_PORT=54320
FORWARD_REDIS_PORT=63790
FORWARD_MAILPIT_PORT=10250
FORWARD_MAILPIT_DASHBOARD_PORT=8026
VITE_PORT=5273
FORWARD_REVERB_PORT=8081
DB_HOST=pgsql
```

`DB_HOST` tiene que ser `pgsql`, no `127.0.0.1`: no hay camino nativo, todo corre dentro de la red de Docker.

- [ ] **Step 3: Instalar Composer usando la imagen ya construida del checkout principal**

`laravel.test` no puede ni construirse sin `vendor/`, porque su contexto de build es `./vendor/laravel/sail/runtimes/8.5`. Se rompe el círculo corriendo Composer dentro de la imagen que el checkout principal ya construyó. `MSYS_NO_PATHCONV=1` es obligatorio en Git Bash: sin él reescribe `/var/www/html` a `C:/Program Files/Git/var/www/html` y Docker lo rechaza.

```bash
MSYS_NO_PATHCONV=1 docker run --rm -u root \
  -v "$(pwd -W):/var/www/html" -w /var/www/html \
  --entrypoint composer sail-8.5/app:latest install --no-interaction
```

- [ ] **Step 4: Levantar el stack**

```bash
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
```

- [ ] **Step 5: Migrar y construir el frontend**

```bash
docker compose exec -T laravel.test php artisan migrate:fresh --force
docker compose exec -T laravel.test bash -lc "pnpm install --frozen-lockfile && rm -f public/hot && pnpm build"
```

- [ ] **Step 6: Correr la suite completa para fijar la línea base**

Run: `docker compose exec -T laravel.test php artisan test`
Expected: `Tests: 496 passed (1457 assertions)`, código de salida 0.

La **primera** corrida después de `up -d` tarda unos diez veces más que las siguientes (~600 s contra ~70 s): es el arranque en frío de Postgres más un opcache frío, no una suite colgada.

- [ ] **Step 7: Sin commit**

Todo lo que produjo esta tarea está en `.gitignore`. No hay nada que commitear. Verificar que sea así:

Run: `git status --short`
Expected: sin salida.

---

### Task 2: Eventos de dominio para `completed` y `no_show`

`CompleteBooking` y `MarkNoShow` no disparan ningún evento hoy, aunque sus dos estados se renderizan en la misma tabla que esta fase pone en vivo. Sin ellos, `Completar` o `Ausencia` dejarían la pestaña de otro miembro del staff mostrando `Confirmada` hasta un refresh manual.

**Files:**
- Create: `app/Events/BookingCompleted.php`
- Create: `app/Events/BookingNoShow.php`
- Modify: `app/Actions/Bookings/CompleteBooking.php`
- Modify: `app/Actions/Bookings/MarkNoShow.php`
- Test: `tests/Feature/Bookings/BookingStatusTransitionsTest.php` (agregar dos tests)

**Interfaces:**
- Consumes: nada.
- Produces: `App\Events\BookingCompleted` y `App\Events\BookingNoShow`, ambos con constructor `__construct(public readonly Booking $booking)` y trait `Dispatchable` — la misma forma exacta que `BookingCreated` y `BookingConfirmed`. La Task 3 mapea estas dos clases; la Task 4 escucha las seis.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar al final de `tests/Feature/Bookings/BookingStatusTransitionsTest.php`, antes de la llave de cierre de la clase:

```php
    public function test_complete_dispatches_the_booking_completed_event(): void
    {
        // Se falsea solo este evento: un Event::fake() sin argumentos también
        // reemplaza los eventos de modelo de Eloquent y rompe el guardado.
        Event::fake([BookingCompleted::class]);

        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        app(CompleteBooking::class)->handle($booking, $staff);

        Event::assertDispatched(
            BookingCompleted::class,
            fn (BookingCompleted $event) => $event->booking->is($booking)
                && $event->booking->status === BookingStatus::Completed
        );
    }

    public function test_mark_no_show_dispatches_the_booking_no_show_event(): void
    {
        Event::fake([BookingNoShow::class]);

        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        app(MarkNoShow::class)->handle($booking, $staff);

        Event::assertDispatched(
            BookingNoShow::class,
            fn (BookingNoShow $event) => $event->booking->is($booking)
                && $event->booking->status === BookingStatus::NoShow
        );
    }
```

Agregar estos `use` al encabezado del archivo, en orden alfabético entre los existentes:

```php
use App\Events\BookingCompleted;
use App\Events\BookingNoShow;
use Illuminate\Support\Facades\Event;
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `docker compose exec -T laravel.test php artisan test --filter=BookingStatusTransitionsTest`
Expected: FAIL. Los dos tests nuevos fallan al cargar la clase, con `Class "App\Events\BookingCompleted" not found`.

- [ ] **Step 3: Crear los dos eventos de dominio**

`app/Events/BookingCompleted.php`:

```php
<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCompleted
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
```

`app/Events/BookingNoShow.php`:

```php
<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingNoShow
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
```

- [ ] **Step 4: Disparar los eventos desde las acciones**

En `app/Actions/Bookings/CompleteBooking.php`, agregar el `use App\Events\BookingCompleted;` y reemplazar el `return $booking->fresh();` final por:

```php
        $booking = $booking->fresh();

        event(new BookingCompleted($booking));

        return $booking;
```

En `app/Actions/Bookings/MarkNoShow.php`, agregar `use App\Events\BookingNoShow;` y reemplazar el `return $booking->fresh();` final por:

```php
        $booking = $booking->fresh();

        event(new BookingNoShow($booking));

        return $booking;
```

Es exactamente el patrón que `ConfirmBooking` y `CancelBooking` ya usan: refrescar primero, disparar con la instancia fresca, devolverla. Ningún otro comportamiento de estas acciones cambia, y ninguno de los dos eventos lleva listener de notificación — esta fase no manda emails nuevos.

- [ ] **Step 5: Correr los tests y verificar que pasan**

Run: `docker compose exec -T laravel.test php artisan test --filter=BookingStatusTransitionsTest`
Expected: PASS, 8 tests.

- [ ] **Step 6: Verificar que no hay regresión en reservas**

Run: `docker compose exec -T laravel.test php artisan test --filter="Bookings|Payments"`
Expected: PASS, sin fallos.

- [ ] **Step 7: Formato y commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
git add app/Events/BookingCompleted.php app/Events/BookingNoShow.php \
        app/Actions/Bookings/CompleteBooking.php app/Actions/Bookings/MarkNoShow.php \
        tests/Feature/Bookings/BookingStatusTransitionsTest.php
git commit -m "feat: dispatch domain events when a booking is completed or marked no-show

These two transitions were the only ones in the booking lifecycle without
an event behind them, even though both statuses render in the dashboard
table. Phase 10 puts that table on a live channel, so a transition with no
event would leave other staff tabs showing a stale status until a manual
refresh.

Both events copy the shape of the four that already exist, and neither
gains a notification listener.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 3: El contrato de cable — `BookingChange` y `BookingChanged`

Una sola clase de broadcast para las seis transiciones, con payload explícito. Sin esta tarea no hay nada que difundir.

**Files:**
- Create: `app/Enums/BookingChange.php`
- Create: `app/Events/Broadcasting/BookingChanged.php`
- Test: `tests/Unit/Enums/BookingChangeTest.php`
- Test: `tests/Unit/Events/BookingChangedTest.php`

**Interfaces:**
- Consumes: los seis eventos de dominio (`BookingCreated`, `BookingConfirmed`, `BookingCancelled`, `BookingRescheduled` ya existían; `BookingCompleted` y `BookingNoShow` vienen de la Task 2).
- Produces:
  - `App\Enums\BookingChange: string` con los casos `Created='created'`, `Confirmed='confirmed'`, `Cancelled='cancelled'`, `Rescheduled='rescheduled'`, `Completed='completed'`, `NoShow='no_show'`, y el método estático `forEvent(object $event): self`.
  - `App\Events\Broadcasting\BookingChanged` con constructor `__construct(public readonly int $businessId, public readonly int $bookingId, public readonly BookingChange $change, public readonly string $updatedAt)`, y los métodos `broadcastOn(): array`, `broadcastAs(): string`, `broadcastWith(): array`. La Task 4 lo construye; la Task 6 depende del nombre de canal `business.{businessId}`; la Task 10 depende del nombre de evento `.booking.changed` en el cliente.

- [ ] **Step 1: Escribir el test del enum que falla**

`tests/Unit/Enums/BookingChangeTest.php`:

```php
<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingChange;
use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BookingChangeTest extends TestCase
{
    public function test_it_exposes_exactly_the_six_approved_values(): void
    {
        $this->assertSame(
            ['created', 'confirmed', 'cancelled', 'rescheduled', 'completed', 'no_show'],
            array_map(fn (BookingChange $case) => $case->value, BookingChange::cases())
        );
    }

    public function test_it_maps_every_booking_domain_event(): void
    {
        $booking = new Booking;

        $cases = [
            [new BookingCreated($booking), BookingChange::Created],
            [new BookingConfirmed($booking), BookingChange::Confirmed],
            [new BookingCancelled($booking, null, ), BookingChange::Cancelled],
            [new BookingRescheduled($booking, CarbonImmutable::parse('2026-08-20 10:00')), BookingChange::Rescheduled],
            [new BookingCompleted($booking), BookingChange::Completed],
            [new BookingNoShow($booking), BookingChange::NoShow],
        ];

        foreach ($cases as [$event, $expected]) {
            $this->assertSame($expected, BookingChange::forEvent($event), $event::class);
        }
    }

    public function test_it_refuses_an_unmapped_event(): void
    {
        $this->expectException(\UnhandledMatchError::class);

        BookingChange::forEvent(new \stdClass);
    }
}
```

Nota: `new BookingCancelled($booking, null, )` usa el tercer parámetro por defecto (`CancellationReason::Requested`), que es lo que interesa acá — el mapeo no depende del motivo.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec -T laravel.test php artisan test --filter=BookingChangeTest`
Expected: FAIL con `Class "App\Enums\BookingChange" not found`.

- [ ] **Step 3: Crear el enum**

`app/Enums/BookingChange.php`:

```php
<?php

namespace App\Enums;

use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;

/**
 * Los seis valores que viajan al navegador en el campo `change` de
 * BookingChanged. Es un contrato de cable, no un estado: `rescheduled` no es
 * un BookingStatus, y `created` puede terminar en `pending` o en `confirmed`
 * según la seña. Por eso no se reutiliza BookingStatus.
 */
enum BookingChange: string
{
    case Created = 'created';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /**
     * El match no tiene `default` a propósito: sumar un evento al listener sin
     * mapearlo acá es un UnhandledMatchError inmediato, no un broadcast
     * silencioso con un valor equivocado.
     */
    public static function forEvent(object $event): self
    {
        return match ($event::class) {
            BookingCreated::class => self::Created,
            BookingConfirmed::class => self::Confirmed,
            BookingCancelled::class => self::Cancelled,
            BookingRescheduled::class => self::Rescheduled,
            BookingCompleted::class => self::Completed,
            BookingNoShow::class => self::NoShow,
        };
    }
}
```

- [ ] **Step 4: Correr el test del enum y verificar que pasa**

Run: `docker compose exec -T laravel.test php artisan test --filter=BookingChangeTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Escribir el test del contrato de cable que falla**

`tests/Unit/Events/BookingChangedTest.php`:

```php
<?php

namespace Tests\Unit\Events;

use App\Enums\BookingChange;
use App\Events\Broadcasting\BookingChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use PHPUnit\Framework\TestCase;

class BookingChangedTest extends TestCase
{
    private function event(): BookingChanged
    {
        return new BookingChanged(
            businessId: 7,
            bookingId: 42,
            change: BookingChange::Confirmed,
            updatedAt: '2026-08-20T18:04:11+00:00',
        );
    }

    public function test_it_declares_queued_broadcasting_after_commit(): void
    {
        $event = $this->event();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_it_broadcasts_on_the_private_business_channel(): void
    {
        $channels = $this->event()->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-business.7', $channels[0]->name);
    }

    public function test_it_uses_a_stable_event_name(): void
    {
        $this->assertSame('booking.changed', $this->event()->broadcastAs());
    }

    public function test_the_payload_is_exactly_the_three_agreed_keys(): void
    {
        $this->assertSame([
            'booking_id' => 42,
            'change' => 'confirmed',
            'updated_at' => '2026-08-20T18:04:11+00:00',
        ], $this->event()->broadcastWith());
    }

    public function test_the_payload_never_carries_the_routing_business_id(): void
    {
        // businessId identifica el canal, no es dato del cliente. Si alguien
        // borra broadcastWith(), Laravel serializa las propiedades públicas
        // del evento y businessId se filtraría al navegador.
        $this->assertArrayNotHasKey('businessId', $this->event()->broadcastWith());
        $this->assertArrayNotHasKey('business_id', $this->event()->broadcastWith());
    }
}
```

- [ ] **Step 6: Correr el test y verificar que falla**

Run: `docker compose exec -T laravel.test php artisan test --filter=BookingChangedTest`
Expected: FAIL con `Class "App\Events\Broadcasting\BookingChanged" not found`.

- [ ] **Step 7: Crear el evento de broadcast**

`app/Events/Broadcasting/BookingChanged.php`:

```php
<?php

namespace App\Events\Broadcasting;

use App\Enums\BookingChange;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Único evento de broadcast de la aplicación. Vive en Events\Broadcasting y no
 * en Events\ a secas para que la frontera quede visible: lo de arriba es
 * dominio, lo de acá es transporte.
 *
 * Sin SerializesModels y sin ninguna propiedad Model: el job encolado nunca
 * vuelve a buscar la reserva en la base, así que no puede toparse con
 * BusinessScope ni filtrar un campo nuevo del modelo por accidente.
 */
class BookingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $bookingId,
        public readonly BookingChange $change,
        public readonly string $updatedAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.'.$this->businessId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.changed';
    }

    /**
     * Pista de invalidación, no datos. El cliente recarga el estado canónico
     * por HTTP, donde las Policies siguen siendo la autoridad.
     *
     * broadcastWith() es obligatorio, no decorativo: sin él Laravel serializa
     * las propiedades públicas y `businessId` — que es enrutamiento — viajaría
     * al navegador.
     *
     * @return array{booking_id: int, change: string, updated_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'change' => $this->change->value,
            'updated_at' => $this->updatedAt,
        ];
    }
}
```

- [ ] **Step 8: Correr el test y verificar que pasa**

Run: `docker compose exec -T laravel.test php artisan test --filter=BookingChangedTest`
Expected: PASS, 5 tests.

- [ ] **Step 9: Formato y commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
git add app/Enums/BookingChange.php app/Events/Broadcasting/BookingChanged.php \
        tests/Unit/Enums/BookingChangeTest.php tests/Unit/Events/BookingChangedTest.php
git commit -m "feat: add the BookingChanged realtime wire contract

One broadcast event for all six booking transitions, carrying three
scalars: booking_id, change and updated_at. broadcastWith() is explicit
rather than inherited, because default serialization of public properties
would put businessId — which is channel routing, not client data — on the
wire.

The event holds no model and no SerializesModels, so the queued job never
re-reads the booking and cannot leak a field added to the model later.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 4: El listener y la semántica after-commit

Traduce los seis eventos de dominio al único evento de broadcast, y prueba la invariante más importante de la fase: nada se difunde por una transacción que después hace rollback.

`ConfirmBooking` dispara `BookingConfirmed` **dentro** de la transacción de `ApplyPaymentResult`, y `CancelBooking` dispara `BookingCancelled` **dentro** de la transacción de `ExpireUnpaidBookings`. Sin `ShouldDispatchAfterCommit` (Task 3) el navegador vería estado que nunca existió.

**Files:**
- Create: `app/Listeners/BroadcastBookingChange.php`
- Test: `tests/Feature/Realtime/BroadcastBookingChangeTest.php`

**Interfaces:**
- Consumes: `App\Enums\BookingChange::forEvent()` y `App\Events\Broadcasting\BookingChanged` (Task 3); los seis eventos de dominio.
- Produces: `App\Listeners\BroadcastBookingChange` con `handle(BookingCreated|BookingConfirmed|BookingCancelled|BookingRescheduled|BookingCompleted|BookingNoShow $event): void`. No lo registra nadie a mano: el autodescubrimiento de listeners lo hace. Verificado en `Illuminate\Foundation\Events\DiscoverEvents::getListenerEvents()`, que usa `Reflector::getParameterClassNames()` y devuelve **todos** los miembros de un tipo unión, así que un solo listener queda registrado para los seis eventos — igual que los cuatro listeners de notificación que ya existen y que nadie registra.

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Realtime/BroadcastBookingChangeTest.php`:

```php
<?php

namespace Tests\Feature\Realtime;

use App\Actions\Bookings\CompleteBooking;
use App\Enums\BookingChange;
use App\Enums\Role;
use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;
use App\Events\Broadcasting\BookingChanged;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class BroadcastBookingChangeTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $overrides = []): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create([
            'name' => 'Cliente Secreto',
            'email' => 'secreto@example.com',
        ]);
        $service = Service::factory()->for($business)->create();

        return Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'notes' => 'nota interna reservada',
            'price' => '1234.56',
        ], $overrides));
    }

    /**
     * @return array<string, array{0: string, 1: BookingChange}>
     */
    public static function transitions(): array
    {
        return [
            'created' => [BookingCreated::class, BookingChange::Created],
            'confirmed' => [BookingConfirmed::class, BookingChange::Confirmed],
            'cancelled' => [BookingCancelled::class, BookingChange::Cancelled],
            'rescheduled' => [BookingRescheduled::class, BookingChange::Rescheduled],
            'completed' => [BookingCompleted::class, BookingChange::Completed],
            'no_show' => [BookingNoShow::class, BookingChange::NoShow],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transitions')]
    public function test_every_domain_transition_produces_exactly_one_safe_broadcast(
        string $domainEvent,
        BookingChange $expected,
    ): void {
        Event::fake([BookingChanged::class]);
        $booking = $this->booking();

        event(match ($domainEvent) {
            BookingCancelled::class => new BookingCancelled($booking, null),
            BookingRescheduled::class => new BookingRescheduled($booking, CarbonImmutable::parse('2026-08-20 10:00')),
            default => new $domainEvent($booking),
        });

        Event::assertDispatchedTimes(BookingChanged::class, 1);
        Event::assertDispatched(BookingChanged::class, function (BookingChanged $event) use ($booking, $expected) {
            $this->assertSame($booking->business_id, $event->businessId);
            $this->assertSame($expected, $event->change);
            $this->assertSame(
                'private-business.'.$booking->business_id,
                $event->broadcastOn()[0]->name
            );
            $this->assertSame('booking.changed', $event->broadcastAs());
            $this->assertSame([
                'booking_id' => $booking->id,
                'change' => $expected->value,
                'updated_at' => $booking->updated_at->toIso8601String(),
            ], $event->broadcastWith());

            return true;
        });
    }

    public function test_the_payload_carries_no_customer_or_money_data(): void
    {
        Event::fake([BookingChanged::class]);
        $booking = $this->booking();

        event(new BookingConfirmed($booking));

        Event::assertDispatched(BookingChanged::class, function (BookingChanged $event) {
            // Esto inspecciona el array que devuelve broadcastWith() — el payload
            // que el broadcaster enviaría —, no un frame real de WebSocket.
            $encoded = json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR);

            foreach (['Cliente Secreto', 'secreto@example.com', 'nota interna reservada', '1234.56'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }

            $this->assertSame(['booking_id', 'change', 'updated_at'], array_keys($event->broadcastWith()));

            return true;
        });
    }

    public function test_nothing_is_broadcast_when_the_surrounding_transaction_rolls_back(): void
    {
        Event::fake([BookingChanged::class]);
        $business = Business::factory()->create();
        $staff = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        try {
            DB::transaction(function () use ($booking, $staff) {
                app(CompleteBooking::class)->handle($booking, $staff);

                throw new RuntimeException('la transacción se cae después de la transición');
            });
        } catch (RuntimeException) {
            // esperado
        }

        Event::assertNotDispatched(BookingChanged::class);
    }

    public function test_it_broadcasts_once_the_surrounding_transaction_commits(): void
    {
        Event::fake([BookingChanged::class]);
        $business = Business::factory()->create();
        $staff = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        DB::transaction(fn () => app(CompleteBooking::class)->handle($booking, $staff));

        Event::assertDispatchedTimes(BookingChanged::class, 1);
    }
}
```

Los dos últimos tests funcionan bajo `RefreshDatabase` porque `Illuminate\Foundation\Testing\DatabaseTransactionsManager` sobreescribe `afterCommitCallbacksShouldBeExecuted($level)` para devolver `$level === 1`, tratando la transacción envolvente del test como raíz.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec -T laravel.test php artisan test --filter=BroadcastBookingChangeTest`
Expected: FAIL. Los 8 casos fallan con `Event::assertDispatched` reportando que `BookingChanged` no se despachó (`test_nothing_is_broadcast_when_the_surrounding_transaction_rolls_back` pasa desde el inicio y es correcto que así sea: todavía no hay listener).

- [ ] **Step 3: Crear el listener**

`app/Listeners/BroadcastBookingChange.php`:

```php
<?php

namespace App\Listeners;

use App\Enums\BookingChange;
use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;
use App\Events\Broadcasting\BookingChanged;

/**
 * Única frontera dominio → transporte. Los eventos de reserva siguen siendo
 * PHP plano y no saben que existe el broadcasting.
 *
 * NO es ShouldQueue: corre en proceso, no hace I/O y solo construye y despacha
 * un objeto. Puede correr dentro de una transacción sin riesgo, porque lo que
 * se difiere al commit es BookingChanged (ShouldDispatchAfterCommit), no él.
 *
 * El tipo unión es lo que registra este listener para los seis eventos sin un
 * Event::listen manual: DiscoverEvents lee los parámetros con
 * Reflector::getParameterClassNames(), que devuelve todos los miembros de la
 * unión.
 */
class BroadcastBookingChange
{
    public function handle(
        BookingCreated|BookingConfirmed|BookingCancelled|
        BookingRescheduled|BookingCompleted|BookingNoShow $event
    ): void {
        $booking = $event->booking;

        event(new BookingChanged(
            businessId: $booking->business_id,
            bookingId: $booking->id,
            change: BookingChange::forEvent($event),
            updatedAt: $booking->updated_at->toIso8601String(),
        ));
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `docker compose exec -T laravel.test php artisan test --filter=BroadcastBookingChangeTest`
Expected: PASS, 9 tests.

- [ ] **Step 5: Confirmar que el autodescubrimiento registró los seis eventos**

Run: `docker compose exec -T laravel.test php artisan event:list --event=Booking`
Expected: la salida lista `App\Listeners\BroadcastBookingChange` bajo los seis eventos `App\Events\Booking*`, y `SendBooking*Notifications` sigue apareciendo bajo los cuatro que ya lo tenían.

- [ ] **Step 6: Verificar que no hay regresión**

Run: `docker compose exec -T laravel.test php artisan test`
Expected: PASS, ≥ 496 tests. Ningún test existente cambia de resultado: con `BROADCAST_CONNECTION=null` el `BroadcastEvent` que produce cada transición se procesa contra `NullBroadcaster`, que no hace nada.

- [ ] **Step 7: Formato y commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
git add app/Listeners/BroadcastBookingChange.php tests/Feature/Realtime/BroadcastBookingChangeTest.php
git commit -m "feat: translate booking domain events into one realtime signal

A single synchronous listener maps all six booking transitions onto
BookingChanged. Laravel's listener discovery registers it for every member
of the union parameter type, so no manual Event::listen is needed and the
domain events stay transport-agnostic.

Covers the invariant that matters most here: two of these transitions fire
inside an open transaction — ConfirmBooking under ApplyPaymentResult and
CancelBooking under expire-unpaid — and a rollback must leave the browser
with nothing.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 5: Instalar Reverb y fijar el contrato de configuración

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `config/broadcasting.php`
- Create + edit: `config/reverb.php`
- Modify: `.env.example`
- Modify: `.env` (ignorado por git; no se commitea)
- Test: `tests/Feature/Realtime/ReverbConfigTest.php`

**Interfaces:**
- Consumes: nada del código anterior.
- Produces: el driver `reverb` disponible en `config('broadcasting.connections.reverb')`, `config('reverb.apps.apps.0.allowed_origins')` como array de hosts parseado desde `REVERB_ALLOWED_ORIGINS`, y `pusher/pusher-php-server` en el árbol de dependencias — que es lo que la Task 6 necesita para poder ejercitar el broadcaster real.

- [ ] **Step 1: Instalar el paquete**

```bash
docker compose exec -T laravel.test composer require laravel/reverb
```

Expected: instala `laravel/reverb v1.11.x` y arrastra `pusher/pusher-php-server ^7.2`, `clue/redis-react`, `ratchet/rfc6455`, `react/socket`.

Run: `docker compose exec -T laravel.test composer show laravel/reverb pusher/pusher-php-server --format=json | grep -o '"name":"[^"]*"'`
Expected: aparecen las dos.

- [ ] **Step 2: Publicar las dos configuraciones**

```bash
docker compose exec -T laravel.test php artisan config:publish broadcasting
docker compose exec -T laravel.test php artisan vendor:publish --tag=reverb-config
```

`config/broadcasting.php` queda tal cual sale del framework, sin editar. `config/reverb.php` se edita en el Step 5.

Run: `ls config/broadcasting.php config/reverb.php`
Expected: existen los dos.

- [ ] **Step 3: Escribir el test de configuración que falla**

`tests/Feature/Realtime/ReverbConfigTest.php`:

```php
<?php

namespace Tests\Feature\Realtime;

use Illuminate\Support\Env;
use Tests\TestCase;

class ReverbConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function reverbConfigWith(?string $allowedOrigins): array
    {
        $repository = Env::getRepository();

        $allowedOrigins === null
            ? $repository->clear('REVERB_ALLOWED_ORIGINS')
            : $repository->set('REVERB_ALLOWED_ORIGINS', $allowedOrigins);

        try {
            return require config_path('reverb.php');
        } finally {
            $repository->clear('REVERB_ALLOWED_ORIGINS');
        }
    }

    public function test_allowed_origins_are_split_and_trimmed(): void
    {
        $config = $this->reverbConfigWith(' localhost , reservas.example.test ');

        $this->assertSame(
            ['localhost', 'reservas.example.test'],
            $config['apps']['apps'][0]['allowed_origins']
        );
    }

    public function test_empty_entries_are_discarded(): void
    {
        $config = $this->reverbConfigWith('localhost,,  ,');

        $this->assertSame(['localhost'], $config['apps']['apps'][0]['allowed_origins']);
    }

    public function test_a_missing_value_fails_closed_to_localhost_only(): void
    {
        // Sin la variable, se acepta solo el origen de desarrollo. Nunca '*':
        // una configuración ausente en producción tiene que negar, no abrir.
        $config = $this->reverbConfigWith(null);

        $this->assertSame(['localhost'], $config['apps']['apps'][0]['allowed_origins']);
    }

    public function test_the_reverb_broadcast_connection_exists(): void
    {
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
    }
}
```

- [ ] **Step 4: Correr el test y verificar que falla**

Run: `docker compose exec -T laravel.test php artisan test --filter=ReverbConfigTest`
Expected: FAIL. Los tres primeros fallan porque `config/reverb.php` recién publicado trae `'allowed_origins' => ['*']` fijo.

- [ ] **Step 5: Editar `allowed_origins` en `config/reverb.php`**

Reemplazar la línea `'allowed_origins' => ['*'],` por:

```php
                // Reverb compara solo el HOST del header Origin — sin esquema y
                // sin puerto — con Str::is(), así que 'localhost' cubre
                // http://localhost y http://localhost:8180 por igual.
                // El default falla cerrado: sin la variable se acepta solo el
                // origen de desarrollo, nunca '*'. El origen real de producción
                // lo aporta el workflow externo de operaciones.
                'allowed_origins' => array_values(array_filter(
                    array_map('trim', explode(',', (string) env('REVERB_ALLOWED_ORIGINS', 'localhost'))),
                    fn (string $origin) => $origin !== ''
                )),
```

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `docker compose exec -T laravel.test php artisan test --filter=ReverbConfigTest`
Expected: PASS, 4 tests.

- [ ] **Step 7: Escribir el contrato de entorno en `.env.example`**

`composer require` y `vendor:publish` no tocan `.env.example`. Reemplazar la línea `BROADCAST_CONNECTION=log` por `BROADCAST_CONNECTION=reverb`, y agregar este bloque justo después de la sección de Redis:

```ini
# Reverb — servidor: dónde escucha el proceso
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Reverb — aplicación. REVERB_APP_SECRET es secreto de servidor y NUNCA
# tiene espejo VITE_*: todo lo que empieza con VITE_ se compila dentro del
# bundle y es público por definición.
REVERB_APP_ID=reservahub-local
REVERB_APP_KEY=local-reverb-key
REVERB_APP_SECRET=local-reverb-secret

# Reverb — dónde encuentra el SERVIDOR a Reverb (red interna de Docker)
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http

# Hosts permitidos, separados por coma. Solo host: sin esquema y sin puerto.
REVERB_ALLOWED_ORIGINS=localhost

# Puerto publicado al host. Solo desarrollo: no predice el puerto de producción.
FORWARD_REVERB_PORT=8080

# Reverb — dónde encuentra el NAVEGADOR a Reverb. Se compilan en el bundle:
# cambiarlos exige volver a correr `pnpm build`.
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT="${FORWARD_REVERB_PORT}"
VITE_REVERB_SCHEME=http
```

- [ ] **Step 8: Copiar las variables al `.env` del worktree**

`.env` está en `.gitignore` y no se actualiza solo. Agregarle el mismo bloque, con dos diferencias propias del worktree (el puerto ya se fijó en la Task 1):

```ini
FORWARD_REVERB_PORT=8081
VITE_REVERB_PORT=8081
```

y cambiar su `BROADCAST_CONNECTION=log` por `BROADCAST_CONNECTION=reverb`.

Run: `docker compose exec -T laravel.test php artisan config:clear && docker compose exec -T laravel.test php artisan tinker --execute="echo config('broadcasting.default');"`
Expected: `reverb`.

- [ ] **Step 9: Verificar que no hay regresión**

Run: `docker compose exec -T laravel.test php artisan test`
Expected: PASS, ≥ 500 tests. `phpunit.xml` sigue forzando `BROADCAST_CONNECTION=null`, así que el `.env` con `reverb` no afecta a la suite.

- [ ] **Step 10: Formato y commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
git add composer.json composer.lock config/broadcasting.php config/reverb.php .env.example \
        tests/Feature/Realtime/ReverbConfigTest.php
git commit -m "feat: install Reverb and pin the broadcasting environment contract

Publishes both configs and replaces Reverb's shipped allowed_origins
wildcard with a comma-separated environment contract that fails closed:
with the variable unset, only the development origin is accepted, never
every origin. Reverb compares the Origin header's host alone, so a bare
hostname covers any port.

REVERB_APP_SECRET is deliberately the one credential without a VITE_
mirror, since anything prefixed VITE_ is compiled into the browser bundle.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 6: Autorización del canal privado

La parte interesante de la fase: probar que el tiempo real no debilita la autorización existente.

**Files:**
- Create: `routes/channels.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Realtime/ChannelAuthorizationTest.php`

**Interfaces:**
- Consumes: el nombre de canal `business.{businessId}` que produce `BookingChanged::broadcastOn()` (Task 3); `pusher/pusher-php-server` (Task 5).
- Produces: el endpoint `POST /broadcasting/auth` autorizando `private-business.{id}` solo para staff activo de ese negocio activo. La Task 10 depende de que ese endpoint exista para que Echo pueda suscribirse.

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Realtime/ChannelAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature\Realtime;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml fija BROADCAST_CONNECTION=null, y NullBroadcaster::auth()
        // es un método vacío: autoriza a cualquiera y NUNCA consulta
        // routes/channels.php. Un test de autorización bajo ese driver pasaría
        // siempre sin probar nada. LogBroadcaster::auth() es igual de vacío.
        //
        // Solo esta clase activa el driver real. No hay llamadas de red: el
        // driver 'reverb' es createPusherDriver() -> PusherBroadcaster, cuyo
        // auth() ejecuta el callback del canal y cuyo
        // validAuthenticationResponse() para un canal `private-` solo calcula
        // un HMAC local.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
            'broadcasting.connections.reverb.options.host' => 'reverb.test',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
    }

    private function authorizeAs(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    private function activeBusiness(): Business
    {
        return Business::factory()->create(['is_active' => true]);
    }

    public function test_a_guest_cannot_authorize(): void
    {
        $business = $this->activeBusiness();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-business.'.$business->id,
        ])->assertStatus(403);
    }

    public function test_an_owner_can_authorize_their_own_business_channel(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.'.$business->id)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_an_admin_can_authorize_their_own_business_channel(): void
    {
        $business = $this->activeBusiness();
        $admin = User::factory()->create(['role' => Role::Admin, 'business_id' => $business->id]);

        $this->authorizeAs($admin, 'private-business.'.$business->id)->assertOk();
    }

    public function test_an_employee_can_authorize_their_own_business_channel(): void
    {
        // Coincide con la autorización HTTP vigente: BookingPolicy::viewAny y
        // Dashboard\BookingController::index dan a cualquier miembro del staff
        // todas las reservas del negocio. Si una fase futura estrecha eso por
        // empleado, el canal se estrecha en el mismo commit y este test cambia
        // de forma consciente.
        $business = $this->activeBusiness();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->authorizeAs($employee, 'private-business.'.$business->id)->assertOk();
    }

    public function test_staff_of_another_business_cannot_authorize(): void
    {
        $businessA = $this->activeBusiness();
        $businessB = $this->activeBusiness();
        $intruder = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessB->id]);

        $this->authorizeAs($intruder, 'private-business.'.$businessA->id)->assertStatus(403);
    }

    public function test_a_customer_cannot_authorize_a_staff_channel(): void
    {
        $business = $this->activeBusiness();
        $customer = User::factory()->customer()->create();

        $this->authorizeAs($customer, 'private-business.'.$business->id)->assertStatus(403);
    }

    public function test_a_deactivated_staff_user_cannot_authorize(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create([
            'role' => Role::Owner,
            'business_id' => $business->id,
            'is_active' => false,
        ]);

        $this->authorizeAs($owner, 'private-business.'.$business->id)->assertStatus(403);
    }

    public function test_staff_of_a_deactivated_business_cannot_authorize(): void
    {
        $business = Business::factory()->create(['is_active' => false]);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.'.$business->id)->assertStatus(403);
    }

    public function test_a_zero_padded_business_id_is_rejected(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.0'.$business->id)->assertStatus(403);
    }

    public function test_a_non_numeric_business_id_is_rejected(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.'.$business->id.'abc')->assertStatus(403);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec -T laravel.test php artisan test --filter=ChannelAuthorizationTest`
Expected: FAIL con 404 en todos los casos: la ruta `/broadcasting/auth` no está registrada porque `withRouting()` todavía no recibe `channels:`.

- [ ] **Step 3: Crear `routes/channels.php`**

```php
<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Único canal de la aplicación. El predicado es la unión exacta de lo que HTTP
 * ya exige: EnsureBusinessContext (rol de staff, usuario activo, negocio
 * activo) más la comprobación de negocio de BookingPolicy::viewAny.
 *
 * El parámetro se tipa string y se compara como string a propósito: con int,
 * PHP coaccionaría '05' y '5abc' a 5 y un identificador forjado entraría a un
 * canal ajeno.
 */
Broadcast::channel('business.{businessId}', function (User $user, string $businessId): bool {
    return in_array($user->role, Role::businessStaff(), true)
        && $user->is_active
        && (string) $user->business_id === $businessId
        && (bool) $user->business?->is_active;
});
```

- [ ] **Step 4: Registrar el archivo de canales**

En `bootstrap/app.php`, agregar `channels:` a la llamada existente de `withRouting()`:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
```

Esto hace que `ApplicationBuilder::withBroadcasting()` registre `Broadcast::routes()` — que usa `['middleware' => ['web']]` por defecto — y haga `require` del archivo. `EnsureBusinessContext` **no** está en el grupo `web` (es un alias por ruta), por eso el callback verifica todo por su cuenta.

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `docker compose exec -T laravel.test php artisan test --filter=ChannelAuthorizationTest`
Expected: PASS, 10 tests.

- [ ] **Step 6: Verificar la ruta y que no hay colisión con Reverb**

Run: `docker compose exec -T laravel.test php artisan route:list --path=broadcasting`
Expected: aparece `GET|POST broadcasting/auth`.

Run: `docker compose exec -T laravel.test php artisan route:list | grep -E "^\s*(GET|POST|PUT|DELETE).*\s(app|apps)/" | head`
Expected: sin salida. Reverb sirve WebSocket en `/app` y su API en `/apps`; la aplicación no define nada ahí.

- [ ] **Step 7: Verificar que no hay regresión**

Run: `docker compose exec -T laravel.test php artisan test`
Expected: PASS, ≥ 510 tests.

- [ ] **Step 8: Formato y commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
git add routes/channels.php bootstrap/app.php tests/Feature/Realtime/ChannelAuthorizationTest.php
git commit -m "feat: authorize the private per-business realtime channel

The channel predicate is the exact union of what HTTP already requires:
staff role, active user, matching business, active business. It grants
nothing the dashboard does not already grant, and a deactivated user is
refused on every new authorization and on reconnect.

The business id is compared as a string rather than coerced to int, so a
zero-padded or suffixed identifier cannot slip into another tenant's
channel.

The test class configures the real broadcaster locally, because the null
driver used by the rest of the suite authorizes everyone without ever
consulting this file.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 7: Integración con la Fase 9

Prueba que los pagos llegan al tiempo real por la transición de dominio normal y que no ganaron ni un byte de código de tiempo real.

**Files:**
- Test: `tests/Feature/Realtime/PaymentRealtimeIntegrationTest.php`

**Interfaces:**
- Consumes: `BookingChanged` (Task 3), el listener (Task 4), y los caminos de Fase 9 ya existentes (`ProcessPaymentWebhook`, `bookings:expire-unpaid`).
- Produces: ninguna interfaz nueva. Es una tarea de sólo tests.

Estos tests **tienen que pasar en la primera corrida**: la arquitectura ya los satisface. Si alguno falla, significa que la convergencia de la Fase 9 sobre `ConfirmBooking` / `CancelBooking` se rompió y hay que arreglar eso, no el test. El único que puede fallar por construcción es el guard de la última clase, y falla justamente si alguien agregó un evento de broadcast específico de pagos.

- [ ] **Step 1: Escribir los tests**

`tests/Feature/Realtime/PaymentRealtimeIntegrationTest.php`:

```php
<?php

namespace Tests\Feature\Realtime;

use App\Enums\BookingChange;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Events\Broadcasting\BookingChanged;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\ProcessPaymentWebhook;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentRealtimeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Booking, 1: Payment}
     */
    private function scenario(array $bookingOverrides = []): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => '10.00']);

        $booking = Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
            'payment_expires_at' => now()->addMinutes(30),
        ], $bookingOverrides));

        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'amount' => '10.00',
            'currency' => 'ARS',
        ]);

        return [$booking, $payment];
    }

    private function approvedEnvelope(Payment $payment, string $eventId): WebhookEnvelope
    {
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        $rawBody = json_encode([
            'event_id' => $eventId,
            'payment_id' => $payment->external_id,
            'status' => PaymentStatus::Approved->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        return new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
        ]);
    }

    public function test_an_approved_deposit_reaches_the_staff_channel_as_a_normal_confirmation(): void
    {
        Event::fake([BookingChanged::class]);
        [$booking, $payment] = $this->scenario();

        app(ProcessPaymentWebhook::class)->handle($this->approvedEnvelope($payment, 'evt_realtime_1'));

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);

        Event::assertDispatchedTimes(BookingChanged::class, 1);
        Event::assertDispatched(BookingChanged::class, function (BookingChanged $event) use ($booking) {
            $this->assertSame(BookingChange::Confirmed, $event->change);
            $this->assertSame($booking->id, $event->bookingId);
            $this->assertSame('private-business.'.$booking->business_id, $event->broadcastOn()[0]->name);

            return true;
        });
    }

    public function test_automatic_unpaid_cancellation_reaches_the_staff_channel_the_same_way(): void
    {
        Event::fake([BookingChanged::class]);
        [$booking] = $this->scenario(['payment_expires_at' => now()->subMinute()]);
        $booking->payments()->update(['status' => PaymentStatus::Expired]);

        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);

        Event::assertDispatchedTimes(BookingChanged::class, 1);
        Event::assertDispatched(
            BookingChanged::class,
            fn (BookingChanged $event) => $event->change === BookingChange::Cancelled
                && $event->bookingId === $booking->id
        );
    }

    public function test_payments_have_no_realtime_class_of_their_own(): void
    {
        // El navegador sólo necesita saber que la RESERVA cambió. Este guard
        // falla si aparece un PaymentApprovedBroadcast, un
        // SimulatedPaymentBroadcast o cualquier evento de WebSocket propio del
        // webhook.
        $classes = array_map('basename', glob(app_path('Events/Broadcasting/*.php')));

        $this->assertSame(['BookingChanged.php'], $classes);
    }
}
```

- [ ] **Step 2: Correr los tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=PaymentRealtimeIntegrationTest`
Expected: PASS, 3 tests, en la primera corrida.

Si `test_an_approved_deposit_...` falla con cero eventos despachados, la causa más probable es que la transacción de `ApplyPaymentResult` no llegó a commitear: revisar ahí, no en el test.

- [ ] **Step 3: Verificar que la suite de pagos sigue intacta**

Run: `docker compose exec -T laravel.test php artisan test --filter=Payments`
Expected: PASS, sin fallos.

- [ ] **Step 4: Formato y commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
git add tests/Feature/Realtime/PaymentRealtimeIntegrationTest.php
git commit -m "test: prove payments reach the realtime channel without knowing it exists

An approved deposit and an automatic unpaid cancellation each produce
exactly one BookingChanged, because ApplyPaymentResult already converges
on ConfirmBooking and expire-unpaid on CancelBooking. No file under
app/Services/Payments or app/Actions/Payments changed for Phase 10.

The last test guards that: it fails the moment a payment-specific
broadcast class appears.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 8: El proceso Reverb en Docker

**Files:**
- Modify: `compose.yaml`

**Interfaces:**
- Consumes: el contrato de entorno de la Task 5.
- Produces: el servicio `reverb`, escuchando en `0.0.0.0:8080` dentro del contenedor y publicado al host en `${FORWARD_REVERB_PORT:-8080}`. La Task 10 depende de que ese puerto esté publicado para que el navegador pueda conectarse.

- [ ] **Step 1: Agregar el servicio**

En `compose.yaml`, insertar este bloque después del servicio `scheduler` y antes de `pgsql`:

```yaml
    reverb:
        build:
            context: './vendor/laravel/sail/runtimes/8.5'
            dockerfile: Dockerfile
            args:
                WWWGROUP: '${WWWGROUP}'
        image: 'sail-8.5/app'
        command: ['php', 'artisan', 'reverb:start', '--host=0.0.0.0', '--port=8080']
        restart: unless-stopped
        extra_hosts:
            - 'host.docker.internal:host-gateway'
        environment:
            WWWUSER: '${WWWUSER}'
            LARAVEL_SAIL: 1
        ports:
            - '${FORWARD_REVERB_PORT:-8080}:8080'
        volumes:
            - '.:/var/www/html'
        networks:
            - sail
        depends_on:
            - pgsql
            - redis
```

El puerto **dentro** del contenedor es siempre 8080; el del host es configurable, para que un stack de worktree no choque con el checkout principal. Ese puerto es de desarrollo y no predice el de producción.

- [ ] **Step 2: Levantar el servicio**

```bash
WWWUSER=1000 WWWGROUP=1000 docker compose up -d reverb
```

- [ ] **Step 3: Verificar que el proceso está arriba**

Run: `docker compose ps reverb`
Expected: estado `Up`.

Run: `docker compose logs reverb --tail=20`
Expected: la línea de arranque de Reverb indicando que escucha en `0.0.0.0:8080`.

- [ ] **Step 4: Verificar que el puerto responde desde la red interna**

Éste es el camino que usa el worker de cola para publicar.

Run: `docker compose exec -T laravel.test php -r "\$s = @fsockopen('reverb', 8080, \$e, \$m, 5); echo \$s ? 'OPEN' : 'CLOSED: '.\$m;"`
Expected: `OPEN`.

- [ ] **Step 5: Verificar que el puerto responde desde el host**

Éste es el camino que usa el navegador.

Run: `docker compose exec -T laravel.test php -r "echo 'host port: '.getenv('FORWARD_REVERB_PORT');"` y después, desde el host: `curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8081/app/local-reverb-key`
Expected: un código HTTP distinto de `000` (Reverb responde al handshake fallido con un código de error, no con una conexión rechazada). Un `000` significa que el puerto no está publicado y hay que revisar `FORWARD_REVERB_PORT` en `.env`.

- [ ] **Step 6: Commit**

```bash
git add compose.yaml
git commit -m "feat: run Reverb as a service in the development stack

Same image as the queue and scheduler containers, listening on 0.0.0.0:8080
inside the container with a configurable host port so a worktree stack does
not collide with the main checkout.

Like the queue worker, this process holds application code in memory and
must be restarted after editing code.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 9: La prop `businessId` en la página de reservas

**Files:**
- Modify: `app/Http/Controllers/Dashboard/BookingController.php`
- Test: `tests/Feature/Dashboard/BookingsTest.php` (agregar un test)

**Interfaces:**
- Consumes: nada.
- Produces: la prop de página `businessId` (entero) en `Dashboard/Bookings/Index`. La Task 10 la lee para construir el nombre del canal.

- [ ] **Step 1: Escribir el test que falla**

Agregar a `tests/Feature/Dashboard/BookingsTest.php`, antes de la llave de cierre de la clase:

```php
    public function test_the_bookings_index_exposes_the_business_id_for_the_realtime_channel(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->actingAs($staff)
            ->get('/dashboard/bookings')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Index')
                ->where('businessId', $business->id)
            );
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec -T laravel.test php artisan test --filter=test_the_bookings_index_exposes_the_business_id_for_the_realtime_channel`
Expected: FAIL con que la prop `businessId` no existe en la página.

- [ ] **Step 3: Pasar la prop**

En `app/Http/Controllers/Dashboard/BookingController.php`, en `index()`:

```php
        return Inertia::render('Dashboard/Bookings/Index', [
            'bookings' => Booking::with(['customer:id,name,email', 'employee:id,name', 'service:id,name'])
                ->orderByDesc('starts_at')
                ->get(),
            'businessId' => Business::current()->id,
        ]);
```

Prop de página, no prop compartida global: solo esta página la necesita. `HandleInertiaRequests::share()` no se toca.

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `docker compose exec -T laravel.test php artisan test --filter=BookingsTest`
Expected: PASS.

- [ ] **Step 5: Formato y commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --test
git add app/Http/Controllers/Dashboard/BookingController.php tests/Feature/Dashboard/BookingsTest.php
git commit -m "feat: expose the business id to the bookings page

The realtime subscriber needs it to build its channel name. It travels as a
page prop rather than as a globally shared Inertia prop, because no other
page needs it.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 10: Echo y el suscriptor de la tabla de reservas

**Files:**
- Modify: `package.json`, `pnpm-lock.yaml`
- Modify: `resources/js/app.jsx`
- Create: `resources/js/Components/BookingsRealtime.jsx`
- Modify: `resources/js/Pages/Dashboard/Bookings/Index.jsx`

**Interfaces:**
- Consumes: el nombre de evento `.booking.changed` y el canal `business.{businessId}` (Task 3), el endpoint `/broadcasting/auth` (Task 6), el puerto publicado (Task 8), la prop `businessId` (Task 9).
- Produces: `BookingsRealtime`, componente por defecto con props `{ businessId: number, only: string[] }` que renderiza `null`.

No hay infraestructura de tests de JavaScript en el repositorio y esta fase no agrega ninguna. La verificación automatizada es `pnpm build`; el comportamiento se verifica a mano en la Task 11.

- [ ] **Step 1: Instalar las dependencias**

```bash
docker compose exec -T laravel.test bash -lc "pnpm add --save-dev laravel-echo pusher-js @laravel/echo-react"
```

Run: `docker compose exec -T laravel.test bash -lc "pnpm list laravel-echo pusher-js @laravel/echo-react"`
Expected: `laravel-echo 2.4.x`, `@laravel/echo-react 2.4.x`, `pusher-js` presente. El peer de React es `^16.8 || ^17 || ^18 || ^19` y el proyecto tiene React 19.2: sin conflicto.

- [ ] **Step 2: Configurar Echo en `resources/js/app.jsx`**

El archivo queda así:

```jsx
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';

// Con solo el broadcaster alcanza: para 'reverb', configureEcho ya toma
// VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT y
// VITE_REVERB_SCHEME de import.meta.env, y fija enabledTransports en
// ['ws', 'wss'].
//
// La llamada es perezosa: guarda la configuración y no construye la instancia
// de Echo hasta la primera suscripción. Por eso no rompe nada cuando el bundle
// se compiló sin configuración de Reverb.
configureEcho({
    broadcaster: 'reverb',
});

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
```

- [ ] **Step 3: Crear el suscriptor**

`resources/js/Components/BookingsRealtime.jsx`:

```jsx
import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

const COALESCE_MS = 250;

/**
 * Único mecanismo de suscripción en tiempo real de la aplicación.
 *
 * El evento es una pista de invalidación, no datos: la respuesta es recargar
 * el estado canónico por Inertia, donde las Policies y el controlador siguen
 * siendo la autoridad sobre qué ve cada usuario.
 *
 * `useEcho` se ocupa de suscribir, desuscribir, limpiar al desmontar y
 * deduplicar el doble montaje de StrictMode; su callback queda memoizado con
 * lista de dependencias vacía, así que no hay que pasarle valores cambiantes.
 * El timer, en cambio, lo crea este componente y este componente lo cancela.
 */
export default function BookingsRealtime({ businessId, only }) {
    const timer = useRef(null);

    useEcho(`business.${businessId}`, '.booking.changed', () => {
        // Una corrida de bookings:expire-unpaid puede cancelar varias reservas
        // en milisegundos. Sin esto serían varias recargas seguidas.
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            router.reload({ only, preserveState: true, preserveScroll: true });
        }, COALESCE_MS);
    });

    // Sin esto, navegar dentro de la ventana de 250 ms dispararía una recarga
    // sobre una página que ya no está montada.
    useEffect(() => () => clearTimeout(timer.current), []);

    return null;
}
```

- [ ] **Step 4: Montar el suscriptor en la tabla**

En `resources/js/Pages/Dashboard/Bookings/Index.jsx`:

a) Agregar los dos imports junto a los existentes:

```jsx
import BookingsRealtime from '../../../Components/BookingsRealtime';
```

b) Agregar dos constantes a nivel de módulo, junto a `STATUS_LABELS` y `CONFIRM_MESSAGES`:

```jsx
// Constante de módulo: el callback de useEcho queda memoizado en el primer
// render, así que conviene que capture siempre el mismo array.
const RELOAD_ONLY = ['bookings'];

// El guard vive en el borde del componente, no dentro del hook: las reglas de
// los hooks prohíben llamar useEcho condicionalmente, y si el bundle se
// compiló sin VITE_REVERB_APP_KEY, pusher-js lanza al construirse y rompería
// el render de la página entera. Montar el suscriptor solo cuando hay
// configuración deja la página utilizable siempre.
//
// Esto cubre el caso de "tiempo real deliberadamente sin configurar". No
// pretende validar cualquier host o puerto mal puesto: con un endpoint mal
// configurado el socket no conecta, y la página y el flujo HTTP/Inertia
// siguen funcionando igual.
const realtimeEnabled = Boolean(import.meta.env.VITE_REVERB_APP_KEY);
```

c) Cambiar la firma del componente para recibir la prop nueva:

```jsx
export default function Index({ bookings, businessId }) {
```

d) Insertar el suscriptor como primer hijo de `<DashboardLayout>`, inmediatamente antes de `<div className="p-8">`:

```jsx
            {realtimeEnabled && <BookingsRealtime businessId={businessId} only={RELOAD_ONLY} />}
```

Nada más de este archivo cambia: la tabla, el formulario de reprogramación y los botones de acción quedan exactamente como estaban.

- [ ] **Step 5: Construir el frontend**

Run: `docker compose exec -T laravel.test bash -lc "rm -f public/hot && pnpm build"`
Expected: build exitoso, `public/build/manifest.json` regenerado.

- [ ] **Step 6: Verificar que las páginas Inertia siguen renderizando**

Un `public/build` roto se manifiesta como ~28 fallos de `Not a valid Inertia response`.

Run: `docker compose exec -T laravel.test php artisan test --filter="Dashboard|Public"`
Expected: PASS, sin fallos.

- [ ] **Step 7: Commit**

```bash
git add package.json pnpm-lock.yaml resources/js/app.jsx \
        resources/js/Components/BookingsRealtime.jsx \
        resources/js/Pages/Dashboard/Bookings/Index.jsx
git commit -m "feat: refresh the bookings table from the realtime channel

One null-rendering subscriber component, mounted once. It answers a
BookingChanged event with an Inertia partial reload rather than mutating
local state, so React never reproduces an authorization or serialization
rule that the server already owns.

The component is mounted only when the bundle carries Reverb
configuration. Hooks cannot be called conditionally, so guarding at the
component boundary is what keeps an unconfigured build from throwing
during render and taking the whole page with it.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

### Task 11: Documentación, smokes manuales y verificación final

**Files:**
- Modify: `docs/DEPLOYMENT_HANDOFF.md`
- Modify: `CLAUDE.md`
- Modify: `01-reservahub.md`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: el contrato de runtime que consume el workflow externo de operaciones.

- [ ] **Step 1: Actualizar `docs/DEPLOYMENT_HANDOFF.md`**

a) En §1, agregar Reverb al diagrama de topología, después de la línea del worker y el scheduler:

```text
worker de cola + scheduler + Reverb (procesos aparte, mismo código)
```

b) En §2, agregar una fila a la tabla de procesos:

| Reverb | `php artisan reverb:start` | Sí, para tiempo real | Proceso de larga vida. Sin él la aplicación funciona entera; solo deja de refrescarse sola la pantalla de reservas |

y agregar bajo la tabla:

```markdown
Reverb, igual que el worker, mantiene el código en memoria: hay que reiniciarlo
en cada deploy. `php artisan reverb:restart` corta las conexiones con gracia y
deja que el gestor de procesos lo vuelva a levantar.
```

c) En §4, cambiar la fila de `BROADCAST_CONNECTION` a:

| `BROADCAST_CONNECTION` | app | no | `reverb` |

y agregar estas filas a continuación:

| `REVERB_APP_ID` | operador | no | Identificador de la aplicación Reverb |
| `REVERB_APP_KEY` | operador | no | Público por diseño: viaja al navegador en el bundle |
| `REVERB_APP_SECRET` | operador | **sí** | Firma las peticiones servidor→Reverb. **Nunca** en una variable `VITE_*` |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | operador | no | Dónde encuentra el **servidor** a Reverb: servicio de la red interna del stack |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | operador | no | Dónde **escucha** Reverb: `0.0.0.0` y el puerto interno |
| `REVERB_ALLOWED_ORIGINS` | operador | no | Hosts separados por coma. Solo host, sin esquema ni puerto; admite comodines `*` de `Str::is()`. Sin valor, acepta solo `localhost`: falla cerrado |
| `VITE_REVERB_APP_KEY` / `VITE_REVERB_HOST` / `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | operador | no | Dónde encuentra el **navegador** a Reverb. **Se compilan dentro del bundle**: cambiarlos exige `pnpm build` y volver a desplegar `public/build`, no alcanza con reiniciar procesos |

d) Agregar una subsección al final de §4:

```markdown
### Dos pares de direcciones que no hay que confundir

`REVERB_HOST`/`REVERB_PORT` es dónde el **servidor** (el worker de cola)
encuentra a Reverb, típicamente un nombre de servicio de la red interna.
`VITE_REVERB_HOST`/`VITE_REVERB_PORT` es dónde lo encuentra el **navegador**,
o sea el host público. `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` es dónde el
propio proceso escucha. Los tres pares son distintos y ninguno reemplaza a otro.
```

e) En §7, agregar a los smoke checks:

```markdown
**Reverb:** el proceso `reverb:start` corriendo y el puerto interno aceptando
conexiones. Un fallo de autorización de canal se ve como un `POST
/broadcasting/auth` con 403; un broadcast encolado que no pudo entregarse queda
en `failed_jobs` y lo lista `php artisan queue:failed`. Reverb escribe sus logs
a stdout del proceso; `reverb:start --debug` imprime el flujo de mensajes y es
solo para diagnóstico.
```

f) En §9, agregar:

```markdown
- `REVERB_APP_SECRET` y cualquier credencial de Reverb que no sea la *key*: la
  key es pública por el protocolo, el secreto no.
```

g) En §10, agregar:

```markdown
- **Proxy con soporte de WebSocket:** el entrypoint público tiene que distinguir
  tres rutas y dos destinos:

  | Ruta | Destino | Protocolo |
  |---|---|---|
  | `/app/*` | Reverb | WebSocket: requiere `Upgrade` / `Connection: Upgrade` y HTTP/1.1 |
  | `/apps/*` | Reverb | HTTP normal (API de publicación del protocolo Pusher) |
  | `/broadcasting/auth` | aplicación Laravel | HTTP normal, autenticado por sesión |
  | todo lo demás | aplicación Laravel | HTTP normal |

  La distinción importa: el proxy necesita upgrade de WebSocket **para Reverb**,
  pero la autorización de canal privado sigue siendo una petición HTTP de la
  aplicación, con cookie de sesión y middleware `web`. No es tráfico de Reverb.

  Preferencia arquitectónica: **una sola frontera pública** de ReservaHub capaz
  de servir HTTP y de hacer upgrade a WebSocket. Reverb es un proceso interno de
  la aplicación, no una segunda aplicación pública, y este repositorio no decide
  hostname, puerto, túnel ni topología de producción.

- **Escala de Reverb:** una sola instancia. `REVERB_SCALING_ENABLED` queda en
  `false`; Redis sigue siendo únicamente el transporte de la cola.
```

- [ ] **Step 2: Actualizar `CLAUDE.md`**

a) En "Project status", cambiar la frase de fases implementadas para incluir la 10 y quitarla de las no empezadas.

b) En la sección de Docker, agregar `reverb` a la lista de servicios de `compose.yaml` y agregar:

```markdown
`reverb` corre `php artisan reverb:start` y, igual que `queue:work`, mantiene el
código en memoria: después de editar un evento, un listener o el archivo de
canales hay que reiniciarlo.

```bash
docker compose restart reverb
```
```

c) En el bloque de puertos para stacks en paralelo, agregar `FORWARD_REVERB_PORT=8081` y `VITE_REVERB_PORT=8081`, con la nota de que `VITE_REVERB_PORT` tiene que coincidir con `FORWARD_REVERB_PORT` y que cambiarlo exige volver a correr `pnpm build`.

d) Agregar una sección nueva antes de "Localization":

```markdown
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
```

e) Agregar los dos smokes manuales:

````markdown
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
````

- [ ] **Step 3: Actualizar `01-reservahub.md`**

Cambiar la fila 10 de la tabla de estado por:

```markdown
| 10 — Tiempo real | Hecha | `laravel/reverb`, `app/Events/Broadcasting/BookingChanged.php`, `app/Listeners/BroadcastBookingChange.php`, `routes/channels.php`, servicio `reverb` en `compose.yaml`, `tests/Feature/Realtime/*` |
```

y ajustar la línea de contexto de la Fase 11 que dice que el tiempo real no existe, para que diga que la Fase 11 rediseña también la tabla de reservas que ya se actualiza sola.

- [ ] **Step 4: Correr la suite completa**

Run: `docker compose exec -T laravel.test php artisan test`
Expected: PASS, ≥ 517 tests, 0 fallos.

- [ ] **Step 5: Verificar formato**

Run: `docker compose exec -T laravel.test vendor/bin/pint --test`
Expected: sin issues.

- [ ] **Step 6: Verificar el build**

Run: `docker compose exec -T laravel.test bash -lc "rm -f public/hot && pnpm build"`
Expected: build exitoso.

- [ ] **Step 7: Ejecutar los dos smokes manuales**

Ejecutar el smoke de dos navegadores y el de fallo de Reverb tal como quedaron documentados en `CLAUDE.md`. Anotar el resultado de cada paso, incluida la evidencia de DevTools del paso 7 (qué canales aparecen suscritos en cada navegador).

Expected: los siete pasos del primero y los seis del segundo, como se describen.

- [ ] **Step 8: Commit**

```bash
git add docs/DEPLOYMENT_HANDOFF.md CLAUDE.md 01-reservahub.md
git commit -m "docs: document Fase 10 realtime and its runtime contract

The handoff gains Reverb as a long-running process, the environment
contract with the secret marked server-only, and the routing requirement
that separates Reverb's WebSocket paths from /broadcasting/auth, which
stays a session-authenticated application request.

It says what Reverb needs and leaves hostname, port, tunnel and process
supervision to the operations workflow that inspects the real machine.

CLAUDE.md gains both manual smokes, including the cross-business check
that reads the subscribed channels out of DevTools instead of inferring
isolation from a table that did not move.

Claude-Session: https://claude.ai/code/session_01EgvTaCNJpALjhnWo4B47SM"
```

---

## Mapa de criterios de aceptación

Los 16 criterios de la §11 de la especificación contra las tareas que los satisfacen:

| # | Criterio | Tarea | Verificación |
|---|---|---|---|
| 1 | Reverb corre localmente | 8 | `docker compose ps reverb` = Up; puerto interno `OPEN` |
| 2 | Cada transición emite una señal | 3, 4 | `BroadcastBookingChangeTest`, 6 casos con `assertDispatchedTimes(1)` |
| 3 | Solo después del commit | 3, 4 | `test_nothing_is_broadcast_when_the_surrounding_transaction_rolls_back` + el de commit |
| 4 | Sin fuga entre negocios | 6 | `test_staff_of_another_business_cannot_authorize`, ids forjados; smoke paso 7 |
| 5 | Autorización = la de HTTP | 6 | Los 10 casos de `ChannelAuthorizationTest` |
| 6 | Sin datos sensibles | 3, 4 | `test_the_payload_carries_no_customer_or_money_data`, contrato exacto en `BookingChangedTest` |
| 7 | La pantalla se actualiza sola | 9, 10 | Smoke de dos navegadores, pasos 3, 5 y 6 |
| 8 | El pago llega por la vía normal | 7 | `test_an_approved_deposit_reaches_the_staff_channel_as_a_normal_confirmation`; smoke paso 4-5 |
| 9 | La expiración llega por la vía normal | 7 | `test_automatic_unpaid_cancellation_reaches_the_staff_channel_the_same_way` |
| 10 | Reconexión y refresh restauran lo canónico | 10 | Recarga parcial de Inertia; smoke de fallo, pasos 5 y 6 |
| 11 | Reverb caído no rompe escrituras | 10, 11 | Smoke de fallo de Reverb, pasos 2 a 4 |
| 12 | Suite verde y Pint limpio | 11 | Steps 4 y 5 |
| 13 | `pnpm build` exitoso | 10, 11 | Step 6 |
| 14 | Smoke de dos navegadores | 11 | Step 7 |
| 15 | Sin Cloudflare en local | 1, 8, 11 | Todo el smoke corre contra `localhost` y el stack de Docker |
| 16 | Handoff documentado sin desplegar | 11 | `docs/DEPLOYMENT_HANDOFF.md`, §§2, 4, 7, 9, 10 |

---

## Autorrevisión del plan

**¿Podría esta implementación de la Fase 10 ser correcta con menos piezas?**

Se simplificaron tres cosas durante la escritura del plan:

1. **`BookingChange` y `BookingChanged` van en una sola tarea.** Son un único contrato de cable; el enum no tiene ningún otro consumidor. Separarlos habría dado dos tareas que ningún revisor puede rechazar de forma independiente.
2. **`configureEcho({ broadcaster: 'reverb' })` sin más argumentos.** Verificado en el bundle de `@laravel/echo-react` 2.4.0: para el broadcaster `reverb`, la librería ya toma `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` y `VITE_REVERB_SCHEME` de `import.meta.env` y fija `enabledTransports: ['ws', 'wss']`, con exactamente las mismas expresiones que la especificación escribía a mano. Pasarlas de nuevo era duplicar valores con riesgo de que se desincronicen.
3. **Las seis transiciones se prueban despachando los eventos de dominio directamente**, no ejecutando las seis acciones completas. Probar `rescheduled` de punta a punta exigiría montar horarios, servicio y disponibilidad para una prueba que es sobre el *listener*; que `RescheduleBooking` dispara su evento ya está cubierto por `tests/Feature/Bookings/RescheduleBookingTest.php`. Las invariantes de commit y rollback sí usan una acción real.

Lo que se evaluó y **no** se simplificó, con su razón:

- **El listener podría desaparecer** si los seis eventos de dominio implementaran `ShouldBroadcast` directamente. Serían seis clases de broadcast en vez de una, seis payloads que mantener sincronizados, y los eventos de dominio quedarían acoplados al transporte. Más piezas, no menos.
- **La tarea 5 y la 6 podrían fusionarse.** Se dejan separadas porque un revisor puede aceptar la autorización del canal y rechazar el parseo de orígenes, o al revés, y porque la 6 depende de que la 5 haya traído `pusher/pusher-php-server`.
- **La tarea 9 podría fusionarse con la 10.** Se dejan separadas porque la 9 tiene test automatizado y la 10 no: unirlas escondería la parte verificable dentro de una tarea que solo se comprueba a mano.

**Cobertura de la especificación.** Cada sección tiene tarea: §3.1 → 2; §3.2-3.4 → 3 y 4; §3.5-3.6 → 4; §3.7 → 7; §4 → 4 y 10; §5 → 6; §6.0-6.3 → 5; §6.1 → 8; §6.4-6.6 → 9 y 10; §7 → 2, 3, 4, 5, 6, 7, 9; §8 → 11; §9.4-9.5 → 11; §10 → 11.

**Consistencia de tipos entre tareas.** `BookingChange::forEvent(object $event): self` (Task 3) es lo que llama el listener (Task 4). `BookingChanged::__construct(int $businessId, int $bookingId, BookingChange $change, string $updatedAt)` (Task 3) es lo que construye el listener con argumentos nombrados (Task 4). El nombre de canal `business.{businessId}` de `broadcastOn()` (Task 3) es el que registra `Broadcast::channel()` (Task 6) y el que arma `useEcho` (Task 10). El nombre de evento `booking.changed` de `broadcastAs()` (Task 3) es `.booking.changed` con punto inicial en el cliente (Task 10). La prop `businessId` (Task 9) es la que consume `BookingsRealtime` (Task 10).

---

## Consideraciones no bloqueantes

- **El worktree necesita su propio `FORWARD_REVERB_PORT`** (8081) distinto del que quedará en `.env.example` (8080). Cuando la rama se integre y el checkout principal levante Reverb, el 8080 queda para él.
- **Un socket ya abierto de un usuario recién desactivado** sigue recibiendo la señal hasta que la conexión se corta. Es comportamiento documentado y aceptado en la §5.6 de la especificación, no un defecto a arreglar en esta fase: el payload no lleva datos y toda lectura canónica pasa por HTTP, donde el usuario ya es rechazado.
- **`config/broadcasting.php` queda con las conexiones de Pusher y Ably** que trae el framework. No se recortan: son configuración inerte del stub oficial y editarlas no aporta nada.
