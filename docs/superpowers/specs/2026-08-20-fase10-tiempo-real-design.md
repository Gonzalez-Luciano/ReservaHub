# Fase 10 — Tiempo real con Laravel Reverb (diseño)

Fecha: 2026-08-20
Estado: aprobado en arquitectura y alcance, pendiente de plan de implementación.
Rama: `feat/phase-10-realtime`

## 1. Objetivo

Cerrar el punto 10 del roadmap con **una** arquitectura de tiempo real completa y correcta, no con
una plataforma de tiempo real. El recorrido que esta fase demuestra de punta a punta es exactamente
uno:

```
transición de dominio de una reserva
        ↓
un evento de broadcast (BookingChanged)
        ↓
después del COMMIT
        ↓
payload mínimo, no sensible
        ↓
canal privado de staff autorizado
        ↓
Reverb
        ↓
Echo (@laravel/echo-react)
        ↓
un componente suscriptor reutilizable
        ↓
recarga parcial de Inertia
        ↓
datos canónicos de Laravel
```

El valor de la fase no es "correr Reverb". Es demostrar que una vía de comunicación en tiempo real
**no debilita la autorización existente**, **no adelanta estado no comprometido** y **no vuelve el
dominio dependiente de un WebSocket**.

**Fuera de alcance, explícito:** chat, presencia, usuarios en línea, indicadores de escritura, feed
de actividad, centro de notificaciones en tiempo real, datos del proveedor de pagos en tiempo real,
event sourcing, replay, historial persistente de mensajes, entrega garantizada, Reverb multi-nodo,
escalado horizontal, `REVERB_SCALING_ENABLED`, Pulse/Telescope, dominio o túnel dedicado para
Reverb, segundo túnel de Cloudflare, tiempo real para el cliente, tiempo real en
`Dashboard/Bookings/Show`, calendario nuevo, rediseño de frontend (Fase 11), CI y despliegue de
producción (Fase 12).

## 2. Punto de partida verificado

Verificado contra el código y una corrida completa de la suite en el checkout principal
(**496 tests, 1457 assertions, 0 fallos, 225 s**, commit `6f016cb`) antes de escribir este documento.

### 2.1 No hay nada de broadcasting todavía

- No existe `config/broadcasting.php`.
- No existe `routes/channels.php`.
- `bootstrap/app.php` llama a `withRouting(web:, api:, commands:, health:)` **sin** `channels:`.
- `.env.example` tiene `BROADCAST_CONNECTION=log`.
- `package.json` no tiene `laravel-echo`, `pusher-js` ni `@laravel/echo-react`.
- `phpunit.xml` fija `BROADCAST_CONNECTION=null` y `QUEUE_CONNECTION=sync`.

### 2.2 Eventos de dominio existentes

`app/Events/` tiene exactamente cuatro clases, todas planas (`Dispatchable`, sin interfaces de cola
ni de broadcast): `BookingCreated`, `BookingConfirmed`, `BookingCancelled`, `BookingRescheduled`.

**`CompleteBooking` y `MarkNoShow` no disparan ningún evento.** Sus transiciones sí se renderizan en
la tabla del panel (`Completada`, `Ausencia`), así que hoy son estados visibles sin evento detrás.

### 2.3 Dos transiciones ocurren dentro de una transacción abierta

Esto es lo que obliga a la semántica after-commit y no es negociable:

- `App\Actions\Payments\ApplyPaymentResult::handle()` envuelve todo en `DB::transaction()` y llama a
  `ConfirmBooking::handle()` **adentro**. `ConfirmBooking` dispara `BookingConfirmed` con la
  transacción todavía abierta.
- `App\Console\Commands\ExpireUnpaidBookings::handle()` llama a `CancelBooking::handle()` dentro de
  un `DB::transaction()` por reserva. `CancelBooking` dispara `BookingCancelled` con la transacción
  todavía abierta.
- `App\Listeners\SendBookingConfirmedNotifications` ya lleva `public bool $afterCommit = true;` con
  un comentario que explica exactamente este problema para los emails. El precedente ya existe en el
  repositorio.

Por contraste, `CreateBooking` y `RescheduleBooking` disparan su evento **después** de que su propia
`DB::transaction()` retorna. La solución tiene que ser correcta en los dos casos.

### 2.4 Autorización de reservas: el staff ya ve todo el negocio

`App\Policies\BookingPolicy::isStaffOfBooking()`:

```php
return in_array($user->role, Role::businessStaff(), true) && $user->business_id === $booking->business_id;
```

No hay estrechamiento por empleado. `BookingPolicy::viewAny()` usa el mismo predicado contra el
negocio. `Dashboard\BookingController::index()` devuelve **todas** las reservas del negocio a
**cualquier** miembro del staff:

```php
Booking::with(['customer:id,name,email', 'employee:id,name', 'service:id,name'])
    ->orderByDesc('starts_at')
    ->get();
```

Conclusión verificada: un único canal de staff por negocio concede exactamente lo que HTTP ya
concede. No amplía nada. Si mañana la política estrechara la visibilidad por empleado, el canal
tendría que estrecharse con ella; §5.4 fija esa obligación por escrito.

`EnsureBusinessContext` agrega dos condiciones más que HTTP ya exige y que el canal tiene que
replicar: `$user->is_active` y `$user->business->is_active`.

### 2.5 UI real del panel

`resources/js/Pages/Dashboard/Bookings/Index.jsx` es una tabla HTML con la prop `bookings`.
**No existe ningún calendario en el repositorio.** El requisito histórico del roadmap
("actualizar calendario en vivo") se interpreta como: *la presentación de reservas efectivamente
implementada se actualiza sola*. Construir un calendario es Fase 11 y solo si Fase 11 lo decide.

`resources/js/app.jsx` son 13 líneas: `createInertiaApp` con `import.meta.glob` y `createRoot`.
No hay store global, ni Redux, ni Zustand, ni bus de eventos. Fase 10 no agrega ninguno.

`HandleInertiaRequests::share()` comparte `auth.user` con `id`, `name` y `role` — **no** comparte
`business_id`.

### 2.6 Infraestructura de cola y procesos

`compose.yaml` define `laravel.test`, `queue`, `scheduler`, `pgsql`, `redis`, `mailpit`.
El worker corre `php artisan queue:work --tries=3 --max-time=3600`. `QUEUE_CONNECTION=redis`,
`QUEUE_FAILED_DRIVER` por defecto `database-uuids`, y la tabla `failed_jobs` existe en
`0001_01_01_000002_create_jobs_table.php`.

`config/queue.php` tiene `'after_commit' => false` en todas las conexiones, incluida `redis`.

### 2.7 No hay infraestructura de tests de JavaScript

`tests/` es 100 % PHP. No hay Vitest, Jest, Playwright ni Cypress. **Fase 10 no agrega ninguno.**

### 2.8 Versiones verificadas

| Paquete | Versión | Verificación |
|---|---|---|
| `laravel/framework` | `^13.8` (instalado) | `composer.json` |
| `laravel/reverb` | `v1.11.1` | Packagist: requiere `illuminate/* ^10.47\|^11.0\|^12.0\|^13.0`, `php ^8.2` |
| `laravel-echo` | `2.4.0` | npm |
| `@laravel/echo-react` | `2.4.0` | npm; peer `react ^16.8 \|\| ^17 \|\| ^18 \|\| ^19` — el repo tiene React `^19.2.8` |
| `pusher-js` | peer de `@laravel/echo-react` | npm |

Gestor de paquetes JS: **pnpm**. `.npmrc` tiene `ignore-scripts=true`.

## 3. Alcance de dominio en tiempo real

### 3.1 Las seis transiciones aprobadas

| Transición | Evento de dominio | Estado |
|---|---|---|
| creada | `App\Events\BookingCreated` | existe |
| confirmada | `App\Events\BookingConfirmed` | existe |
| cancelada | `App\Events\BookingCancelled` | existe |
| reprogramada | `App\Events\BookingRescheduled` | existe |
| completada | `App\Events\BookingCompleted` | **nuevo** |
| ausencia | `App\Events\BookingNoShow` | **nuevo** |

Los dos eventos nuevos existen porque sus estados **ya se muestran** en la misma tabla que esta fase
pone en vivo: sin ellos, `Completar` o `Ausencia` pulsados por un miembro del staff dejarían la
pestaña de otro mostrando `Confirmada` hasta un refresh manual. Es cerrar un hueco visible, no
ampliar el alcance.

Ambos copian literalmente la forma de los existentes:

```php
namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCompleted
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
```

`CompleteBooking` y `MarkNoShow` cambian **solo** en que, tras `$booking->fresh()`, disparan su
evento y devuelven la instancia fresca — el mismo patrón que `ConfirmBooking` y `CancelBooking` ya
usan. Ningún otro comportamiento de esas acciones se toca. Ninguno de los dos eventos nuevos tiene
listener de notificación: esta fase no manda emails nuevos.

### 3.2 Frontera entre evento de dominio y evento de broadcast

Los eventos de dominio siguen siendo PHP plano y **no saben que existe el broadcasting**. Un único
listener traduce dominio → transporte:

```
BookingCreated ┐
BookingConfirmed │
BookingCancelled ├─→ App\Listeners\BroadcastBookingChange (síncrono)
BookingRescheduled │      └─→ event(new App\Events\Broadcasting\BookingChanged(...))
BookingCompleted │
BookingNoShow ┘
```

`App\Listeners\BroadcastBookingChange`:

```php
namespace App\Listeners;

use App\Enums\BookingChange;
use App\Events\Broadcasting\BookingChanged;
use App\Events\{BookingCancelled, BookingCompleted, BookingConfirmed,
                BookingCreated, BookingNoShow, BookingRescheduled};

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

**No es `ShouldQueue`.** Corre en proceso, no hace I/O y solo construye y despacha un objeto. Puede
correr dentro de la transacción sin riesgo, porque lo que se difiere al commit es el evento que
despacha, no él.

El tipo unión funciona con el autodescubrimiento del framework. Verificado en
`Illuminate\Foundation\Events\DiscoverEvents::getListenerEvents()`: usa
`Reflector::getParameterClassNames($method->getParameters()[0])`, que devuelve **un array** con
todos los miembros de una unión. Un solo listener queda registrado para los seis eventos sin ningún
`Event::listen` manual — igual que los cuatro listeners de notificación que ya existen y que nadie
registra a mano.

### 3.3 El enum `BookingChange`

`app/Enums/BookingChange.php`. El mapeo es exhaustivo y estable: **estos seis valores son el
contrato de cable** que ve el navegador.

```php
namespace App\Enums;

use App\Events\{BookingCancelled, BookingCompleted, BookingConfirmed,
                BookingCreated, BookingNoShow, BookingRescheduled};

enum BookingChange: string
{
    case Created = 'created';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
    case Completed = 'completed';
    case NoShow = 'no_show';

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

| Evento de dominio | `BookingChange` | Valor en el payload |
|---|---|---|
| `BookingCreated` | `Created` | `"created"` |
| `BookingConfirmed` | `Confirmed` | `"confirmed"` |
| `BookingCancelled` | `Cancelled` | `"cancelled"` |
| `BookingRescheduled` | `Rescheduled` | `"rescheduled"` |
| `BookingCompleted` | `Completed` | `"completed"` |
| `BookingNoShow` | `NoShow` | `"no_show"` |

El `match` sin `default` es deliberado: agregar un evento al listener sin agregarlo acá es un
`UnhandledMatchError` inmediato, no un broadcast silencioso con un valor equivocado.

`BookingChange` no repite `BookingStatus`. `rescheduled` no es un estado, y `created` puede terminar
en `pending` o en `confirmed` según la seña. Son dos conceptos distintos y se mantienen separados.

### 3.4 El contrato de cable: `BookingChanged`

`app/Events/Broadcasting/BookingChanged.php`. El subdirectorio `Broadcasting/` hace visible la
frontera del §3.2: todo lo que está en `app/Events/` a secas es dominio; lo que está acá es
transporte.

```php
namespace App\Events\Broadcasting;

use App\Enums\BookingChange;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BookingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $bookingId,
        public readonly BookingChange $change,
        public readonly string $updatedAt,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.'.$this->businessId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.changed';
    }

    /** @return array{booking_id: int, change: string, updated_at: string} */
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

Tres decisiones y sus razones:

1. **`broadcastWith()` es obligatorio, no opcional.** Sin él, Laravel serializa las propiedades
   públicas del evento y `businessId` viajaría al cliente. `businessId` es **enrutamiento**, no
   dato: identifica el canal y nada más. El test del §7.2 fija el contrato exacto de
   `broadcastWith()` — clave por clave y valor por valor — precisamente para que nadie pueda volver
   a la serialización por defecto sin romper la suite.
2. **Sin `SerializesModels` y sin ninguna propiedad `Model`.** El evento lleva escalares. El job
   encolado nunca vuelve a buscar el `Booking` en la base, así que nunca puede toparse con
   `BusinessScope` ni resucitar una fila que ya cambió. También hace imposible que un campo nuevo
   del modelo se filtre al cable por accidente.
3. **`updatedAt` es un string ISO-8601 ya formateado**, no un `Carbon`. Un objeto de fecha en el
   constructor volvería a introducir serialización dependiente de configuración.

`booking_id` está en el payload porque identifica **qué** cambió; el cliente puede usarlo para
loguear o filtrar. No es dato de negocio: sin autorización sobre el canal no se recibe, y con
autorización sobre el canal el usuario ya podía leer esa reserva entera por HTTP.

`change` está en el payload porque hace legible la demo y el log del navegador. No expone nada que
la tabla no muestre ya.

### 3.5 Broadcast solo de estado comprometido

`BookingChanged implements ShouldDispatchAfterCommit` es el mecanismo oficial y verificado.
`Illuminate\Events\Dispatcher::dispatch()`:

```php
if ($isEventObject &&
    $parsedPayload[0] instanceof ShouldDispatchAfterCommit &&
    ! is_null($transactions = $this->resolveTransactionManager())) {
    $transactions->addCallback(
        fn () => $this->invokeListeners($parsedEvent, $parsedPayload, $halt)
    );

    return null;
}
```

Consecuencias verificadas:

- Dentro de una transacción, **no se invoca ningún listener** — ni siquiera el de broadcasting —
  hasta el commit de la transacción raíz.
- Si la transacción hace rollback,
  `Illuminate\Database\DatabaseTransactionsManager::rollback()` descarta el registro y sus
  callbacks. **El navegador nunca ve un cambio que no ocurrió.**
- Fuera de toda transacción (`CreateBooking`, `RescheduleBooking`, una confirmación manual desde el
  panel), no hay transacción activa y el despacho es inmediato. El mismo mecanismo cubre los dos
  casos sin ramificar.

Esto se elige por encima de poner `'after_commit' => true` en `config/queue.php`: cambiar la
conexión afectaría a **todos** los jobs de la aplicación, incluidos los de notificación y los de
pagos, cuya semántica actual está probada por 496 tests. Fase 10 no cambia la semántica de cola de
nadie más.

**Es comprobable con `RefreshDatabase`**, aunque la suite envuelva cada test en su propia
transacción. Verificado en `Illuminate\Foundation\Testing\DatabaseTransactionsManager`, que
`RefreshDatabase::beginDatabaseTransaction()` instala en lugar del manager normal:

```php
public function afterCommitCallbacksShouldBeExecuted($level)
{
    return $level === 1;
}
```

Trata el nivel 1 (la transacción envolvente del test) como raíz, así que los callbacks after-commit
de una transacción de aplicación **sí** se ejecutan durante el test, y un rollback de la
transacción de aplicación **sí** los descarta.

### 3.6 Entrega encolada, no inmediata

`ShouldBroadcast` (no `ShouldBroadcastNow`) pone un `Illuminate\Broadcasting\BroadcastEvent` en la
conexión de cola por defecto: **Redis, cola `default`, consumida por el contenedor `queue` que ya
existe**. No hay cola nueva, ni conexión nueva, ni segundo sistema de colas.

La petición HTTP que crea o confirma una reserva **nunca abre una conexión a Reverb**. Esa es la
propiedad que hace que la corrección del dominio no dependa del tiempo real.

### 3.7 Pagos: cero acoplamiento con el tiempo real

`app/Actions/Payments/`, `app/Services/Payments/` y `app/Jobs/DeliverSimulatedProviderWebhook.php`
**no se tocan**. Los caminos ya convergen donde tienen que converger:

```
webhook aprobado  →  ProcessPaymentWebhook  →  ApplyPaymentResult  →  ConfirmBooking
                                                                   →  BookingConfirmed
                                                                   →  BroadcastBookingChange
                                                                   →  BookingChanged(confirmed)

ventana vencida   →  bookings:expire-unpaid  →  CancelBooking(PaymentWindowExpired)
                                                                   →  BookingCancelled
                                                                   →  BroadcastBookingChange
                                                                   →  BookingChanged(cancelled)
```

No existe `PaymentApprovedBroadcast`, ni `SimulatedPaymentBroadcast`, ni evento de WebSocket
específico de webhooks. El navegador solo necesita saber que **la reserva** cambió; los internos del
proveedor no le incumben. El §7.3 lo fija con un test que falla si alguien agrega una clase de
broadcast bajo `App\Events\Broadcasting` que no sea `BookingChanged`.

## 4. Un evento por transición: sin tormentas de recarga

Cada acción de dominio dispara su evento **una** vez, y el listener produce **un** `BookingChanged`.
No hay fan-out en el servidor.

El caso real de ráfaga está del lado del cliente: `bookings:expire-unpaid` puede cancelar varias
reservas en una sola corrida, produciendo varios `BookingChanged` en milisegundos. El cliente los
agrupa con **un `setTimeout` de 250 ms** (§6.3). Eso es todo el mecanismo de coalescencia: no hay
subsistema de batching, ni cola de eventos en el navegador, ni store.

## 5. Canales y autorización

### 5.1 Topología: un solo tipo de canal

```
private-business.{businessId}    ← owner | admin | employee activo de ese negocio
```

Sin canal por reserva. Sin canal de presencia. Sin canal de usuario. El cliente hace acciones HTTP
normales y no se suscribe a nada (§5.5).

### 5.2 `routes/channels.php`

```php
<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('business.{businessId}', function (User $user, string $businessId): bool {
    return in_array($user->role, Role::businessStaff(), true)
        && $user->is_active
        && (string) $user->business_id === $businessId
        && (bool) $user->business?->is_active;
});
```

El parámetro se tipa **`string`** y se compara como string a propósito. Con `int`, PHP coaccionaría
`'5abc'` a `5` y `'05'` a `5`, y un identificador forjado entraría a un canal ajeno. La comparación
textual rechaza relleno de ceros, sufijos y cualquier variante no canónica sin necesitar una
validación aparte.

El predicado es la unión exacta de lo que HTTP ya exige:

| Condición | Origen en HTTP |
|---|---|
| `role ∈ Role::businessStaff()` | `EnsureBusinessContext`, `BookingPolicy::viewAny` |
| `is_active` | `EnsureBusinessContext` |
| `business_id` coincide | `EnsureBusinessContext`, `BookingPolicy::viewAny` |
| `business->is_active` | `EnsureBusinessContext` |

### 5.3 Registro del endpoint de autorización

`bootstrap/app.php` agrega un argumento a la llamada existente:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    channels: __DIR__.'/../routes/channels.php',
    health: '/up',
)
```

Verificado en `ApplicationBuilder::withRouting()`: si `channels` es una ruta real, llama a
`withBroadcasting()`, que registra `Broadcast::routes()` y hace `require` del archivo.
`BroadcastManager::routes()` usa por defecto `['middleware' => ['web']]`, así que
`/broadcasting/auth` autentica por sesión, igual que el panel. `EnsureBusinessContext` **no** está
en el grupo `web` (es un alias por ruta), por eso el callback del canal hace su propia verificación
completa en vez de asumir contexto de negocio.

`/broadcasting/auth` es una **petición HTTP normal de la aplicación**, no tráfico de Reverb.
Esta distinción es parte del contrato de despliegue (§8.4).

### 5.4 Obligación permanente

El canal y `BookingPolicy` tienen que seguir concediendo lo mismo. Si una fase futura estrecha la
visibilidad de reservas por empleado, **el canal se estrecha en el mismo commit**. El tiempo real
nunca amplía un permiso de HTTP por conveniencia. Los tests del §7.1 incluyen el caso de empleado
justamente para que ese día fallen y obliguen a decidirlo de forma consciente.

### 5.5 El cliente no se suscribe a nada

El cliente sigue con HTTP/Inertia normal. Un `customer` es rechazado por el callback del canal de
staff (falla en la primera condición). No hay canal de usuario, no hay `private-user.{id}`, no hay
`App.Models.User.{id}`. Si Fase 11 decide que el tiempo real del cliente mejora la demo, agregar un
segundo tipo de canal es aditivo y no obliga a rehacer nada de esto.

### 5.6 Usuarios desactivados y sockets de larga vida

Comportamiento verificado y aceptado, no un sistema nuevo:

- **Nueva autorización o reconexión:** denegada. `is_active` se evalúa en cada
  `POST /broadcasting/auth`, y Echo vuelve a autorizar cada canal al reconectar.
- **Socket ya abierto:** sigue recibiendo hasta que la conexión se corta (navegación, cierre de
  pestaña, reinicio de Reverb, timeout de actividad). Reverb no ofrece un mecanismo simple para
  expulsar por usuario, y **no se construye seguimiento de conexiones propio**.
- **Por qué el riesgo es acotado:** el payload es `{booking_id, change, updated_at}`. Un usuario
  desactivado con un socket todavía abierto aprende que *alguna* reserva cambió — y no puede
  recuperar ningún dato, porque toda lectura canónica pasa por HTTP, donde `EnsureBusinessContext`
  ya lo rechaza con 403. La ventana es corta y no filtra información de negocio.

`UserAccessRevoker` (Fase 8) no cambia: sigue rotando `remember_token`, borrando tokens de Sanctum y
borrando filas de `sessions`. Sin sesión, la siguiente autorización de canal falla.

## 6. Reverb, Echo y la UI existente

### 6.0 Instalación: resultado fijado, no comando mágico

`php artisan install:broadcasting` existe y detecta pnpm correctamente (verificado en
`BroadcastingInstallCommand::installNodeDependencies()`: si hay `pnpm-lock.yaml` corre
`pnpm add --save-dev laravel-echo pusher-js --ignore-scripts` y `pnpm run build`). Pero **no** sirve
tal cual acá: escribe el scaffolding de Echo en `resources/js/app.js` y este proyecto tiene
`app.jsx`, y no conoce `@laravel/echo-react`.

Por eso lo que esta fase fija es el **resultado en archivos**, no el comando:

| Archivo | Cómo llega |
|---|---|
| `composer.json` / `composer.lock` | `composer require laravel/reverb` (arrastra `pusher/pusher-php-server ^7.2`) |
| `config/reverb.php` | publicado por el paquete, y después editado a mano en `allowed_origins` (§6.3) |
| `config/broadcasting.php` | publicado (no existe hoy); se deja tal cual sale del framework |
| `routes/channels.php` | escrito a mano (§5.2) |
| `bootstrap/app.php` | editado a mano: se agrega `channels:` a `withRouting()` (§5.3) |
| `.env.example` | editado a mano (§6.2) |
| `compose.yaml` | editado a mano (§6.1) |
| dependencias JS | `pnpm add --save-dev laravel-echo pusher-js @laravel/echo-react` |
| `resources/js/app.jsx`, `Components/BookingsRealtime.jsx`, `Pages/Dashboard/Bookings/Index.jsx` | escritos a mano (§6.4) |

El plan de implementación verifica cada uno de esos archivos, sin importar qué comando los generó.
Quien desarrolle tiene que copiar además las variables nuevas de `.env.example` a su propio `.env`,
que está en `.gitignore` y no se actualiza solo.

### 6.1 Proceso local

Servicio nuevo en `compose.yaml`, misma imagen que `queue` y `scheduler`:

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

El puerto **dentro** del contenedor es siempre 8080; el puerto **del host** es
`FORWARD_REVERB_PORT`, para que un worktree paralelo pueda levantar su propio stack sin chocar,
igual que `FORWARD_DB_PORT` y los demás. Ese puerto es de desarrollo y no predice nada sobre
producción.

Como `reverb:start` es un proceso de larga vida que mantiene el código en memoria, **hay que
reiniciarlo tras editar código de la aplicación**, igual que el worker:
`docker compose restart reverb` (o `php artisan reverb:restart`, que corta las conexiones con
gracia y deja que el gestor de procesos lo levante de nuevo).

### 6.2 Dos pares de direcciones distintos

Esta es la confusión que la documentación oficial advierte explícitamente, y en Docker es literal:

| Sentido | Variables | Valor local | Por qué |
|---|---|---|---|
| Servidor → Reverb (el worker publica) | `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | `reverb`, `8080`, `http` | El worker vive en la red `sail`; `reverb` es el nombre del servicio |
| Navegador → Reverb (el socket) | `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | `localhost`, `${FORWARD_REVERB_PORT}`, `http` | El navegador vive en el host y habla con el puerto publicado |
| Reverb consigo mismo (dónde escucha) | `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | `0.0.0.0`, `8080` | Todas las interfaces del contenedor |

Añadidos a `.env.example`:

```ini
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=reservahub-local
REVERB_APP_KEY=local-reverb-key
REVERB_APP_SECRET=local-reverb-secret
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=localhost
FORWARD_REVERB_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT="${FORWARD_REVERB_PORT}"
VITE_REVERB_SCHEME=http
```

**`REVERB_APP_SECRET` no tiene ni tendrá espejo `VITE_`.** `VITE_*` se compila dentro del bundle y
es público por definición. Solo la *key* (identificador de aplicación, pensado para ser público en
el protocolo Pusher) cruza esa frontera. La *secret* firma las peticiones del servidor a la API de
Reverb y se queda del lado del servidor.

`phpunit.xml` mantiene `BROADCAST_CONNECTION=null`: los tests usan `Event::fake` y nunca necesitan
un broadcaster real (§7).

### 6.3 Orígenes permitidos

Comportamiento verificado en el fuente de Reverb
(`Laravel\Reverb\Protocols\Pusher\Server::verifyOrigin()`):

```php
if (in_array('*', $allowedOrigins)) { return; }

$origin = parse_url($connection->origin(), PHP_URL_HOST);

foreach ($allowedOrigins as $allowedOrigin) {
    if (Str::is($allowedOrigin, $origin)) { /* aceptado */ }
}
```

Es decir: se compara **solo el host** — sin esquema y sin puerto — con `Str::is()`, que soporta
comodines `*`. Por eso `localhost` cubre `http://localhost` y `http://localhost:8180` sin más.

`config/reverb.php` **no** conserva el `['*']` que trae el paquete. Se publica y se edita a un
contrato de entorno portable, que representa uno o varios hosts separados por coma:

```php
'allowed_origins' => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('REVERB_ALLOWED_ORIGINS', 'localhost'))
), fn (string $origin) => $origin !== '')),
```

- **Local:** `REVERB_ALLOWED_ORIGINS=localhost`.
- **Producción:** el valor lo aporta el workflow externo de operaciones cuando conozca el host real.
  Este repositorio **no** escribe un dominio de producción, y **no** deja `*` como valor por defecto
  para producción.

El default `'localhost'` del `env()` **falla cerrado**: si en producción la variable no está puesta,
las conexiones desde el host público se rechazan, en vez de aceptarse desde cualquier origen. Es el
mismo criterio que `PAYMENTS_SIMULATED_WEBHOOK_SECRET` en la Fase 8/9 — ante configuración ausente,
negar, no abrir.

### 6.4 Frontend: tres archivos, una llamada

```
resources/js/app.jsx                              + configureEcho({ ... })
resources/js/Components/BookingsRealtime.jsx      nuevo (~35 líneas, renderiza null)
resources/js/Pages/Dashboard/Bookings/Index.jsx   + una línea de JSX
```

Dependencias: `pnpm add --save-dev laravel-echo pusher-js @laravel/echo-react`.

`resources/js/app.jsx`:

```jsx
import { configureEcho } from '@laravel/echo-react';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

`resources/js/Components/BookingsRealtime.jsx` — el único mecanismo de suscripción reutilizable de
la fase:

```jsx
import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

const COALESCE_MS = 250;

export default function BookingsRealtime({ businessId, only }) {
    const timer = useRef(null);

    useEcho(`business.${businessId}`, '.booking.changed', () => {
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            router.reload({ only, preserveState: true, preserveScroll: true });
        }, COALESCE_MS);
    });

    // useEcho limpia la suscripción de Echo, pero el timer es nuestro:
    // sin esto, una navegación dentro de la ventana de 250 ms dispararía
    // una recarga sobre una página que ya no está montada.
    useEffect(() => () => clearTimeout(timer.current), []);

    return null;
}
```

`useEcho` de `@laravel/echo-react` se ocupa de suscribir, desuscribir, limpiar en el desmontaje y
deduplicar el doble montaje de StrictMode — exactamente la clase de bug de "listener duplicado" que
de otro modo tendríamos que escribir y probar a mano sin infraestructura de tests de JS. El timer,
en cambio, lo crea este componente, así que este componente lo cancela: un `useRef` y un
`clearTimeout` en el cleanup. No hay abstracción de debounce, ni librería, ni hook genérico.

`resources/js/Pages/Dashboard/Bookings/Index.jsx`:

```jsx
export default function Index({ bookings, businessId }) {
    // Todo el cuerpo actual del componente queda igual: estado de reprogramación,
    // act(), startReschedule(), onRescheduleDateChange(), submitReschedule().
    return (
        <DashboardLayout>
            {realtimeEnabled && <BookingsRealtime businessId={businessId} only={['bookings']} />}
            {/* la tabla existente, sin cambios */}
        </DashboardLayout>
    );
}
```

con, a nivel de módulo:

```jsx
const realtimeEnabled = Boolean(import.meta.env.VITE_REVERB_APP_KEY);
```

**El guard vive en el borde del componente, no dentro del hook, y eso es deliberado.** Las reglas de
los hooks prohíben llamar `useEcho` condicionalmente; si el bundle se compiló sin
`VITE_REVERB_APP_KEY`, `pusher-js` lanza al construirse y **rompería el render de la página entera**.
Montar el suscriptor solo cuando hay configuración mantiene la invariante del §9.2 como una
propiedad estructural del código y no como una esperanza.

`businessId` llega como **prop de página**, no como prop compartida global: solo esta página lo
necesita. `Dashboard\BookingController::index()` cambia únicamente en eso:

```php
return Inertia::render('Dashboard/Bookings/Index', [
    'bookings' => Booking::with(['customer:id,name,email', 'employee:id,name', 'service:id,name'])
        ->orderByDesc('starts_at')
        ->get(),
    'businessId' => Business::current()->id,
]);
```

`HandleInertiaRequests::share()` no se toca.

### 6.5 Recarga de Inertia, no estado local duplicado

El evento es una **pista de invalidación**. La verdad vuelve por el camino canónico:

```
BookingChanged → router.reload({ only: ['bookings'] }) → BookingController::index()
               → authorize('viewAny') → consulta con scope de negocio → props frescas
```

`BookingPolicy` sigue siendo la autoridad y `BookingResource`/la consulta del controlador siguen
siendo la única serialización. React no reproduce a mano ninguna regla de autorización ni de
formato, y no hay un segundo contrato de serialización que pueda quedar desincronizado.

`preserveState: true` conserva el estado local del formulario de reprogramación abierto;
`preserveScroll: true` evita que la tabla salte.

### 6.6 Reconexión: sin garantías, a propósito

No hay entrega garantizada, ni replay, ni historial. Un cliente puede perder eventos y eso es
aceptable, porque el evento nunca es la fuente de verdad. Navegación, reconexión y refresh manual
traen el estado canónico por HTTP como siempre. PostgreSQL sigue siendo la única historia.

## 7. Tests

Todos en PHP, en `tests/Feature/Realtime/`. **No se agrega ninguna infraestructura de tests de
JavaScript** (§2.7). El frontend se verifica con `pnpm build` y con el smoke manual de dos
navegadores (§9.4).

Los tests usan `Event::fake([BookingChanged::class])`, que deja correr de verdad los seis eventos de
dominio y el listener — o sea, cubren la traducción completa, no solo la clase final. Verificado en
`Illuminate\Support\Testing\Fakes\EventFake::fakeEvent()`: respeta `ShouldDispatchAfterCommit`
registrando el evento en el manager de transacciones, así que los tests de commit y de rollback son
fieles al comportamiento real.

**Alcance de los asserts, dicho con precisión:** estos tests inspeccionan la instancia del evento y
el array que devuelve `broadcastWith()` — es decir, el payload que el broadcaster enviaría. **No
capturan un frame real de WebSocket** ni levantan Reverb. La verificación de transporte real es el
smoke manual del §9.4.

### 7.1 `ChannelAuthorizationTest`

**Trampa verificada que este test tiene que esquivar.** `phpunit.xml` fija
`BROADCAST_CONNECTION=null`, y `Illuminate\Broadcasting\Broadcasters\NullBroadcaster::auth()` es un
método vacío: devuelve éxito para cualquiera y **nunca consulta `routes/channels.php`**. Un test de
autorización contra `/broadcasting/auth` bajo el driver `null` pasaría siempre y no probaría nada.
`LogBroadcaster::auth()` es igual de vacío.

Por eso **esta clase de test —y solo esta— configura el driver real en `setUp()`**, con credenciales
ficticias:

```php
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
```

Esto no hace ninguna llamada de red. Verificado: el driver `reverb` es
`createPusherDriver()` → `PusherBroadcaster`, cuyo `auth()` delega en
`Broadcaster::verifyUserCanAccessChannel()` (que sí ejecuta el callback de `routes/channels.php`), y
cuyo `validAuthenticationResponse()` para un canal `private-` solo calcula un HMAC local con
`authorizeChannel()`. `pusher/pusher-php-server ^7.2` viene como dependencia de `laravel/reverb`, así
que la clase está disponible en el entorno de test.

**`BROADCAST_CONNECTION=null` se queda como valor global de `phpunit.xml`**, y eso también es
deliberado: `QUEUE_CONNECTION=sync` hace que un `BookingChanged` no falseado se difunda en el acto,
así que un driver real por defecto haría que **cada test existente que crea o cambia una reserva**
intentara una llamada HTTP a un Reverb inexistente. El driver real se activa solo donde se está
probando la autorización.

Casos, contra `POST /broadcasting/auth` con `channel_name=private-business.{id}` y un `socket_id`
válido:

| Caso | Esperado |
|---|---|
| Invitado sin sesión | no autorizado |
| `owner` del negocio A → canal de A | autorizado |
| `admin` del negocio A → canal de A | autorizado |
| `employee` del negocio A → canal de A | autorizado (§5.4) |
| `owner` del negocio B → canal de A | denegado |
| `customer` → canal de A | denegado |
| Staff de A con `is_active = false` | denegado |
| Staff de A con `business.is_active = false` | denegado |
| Identificador `'0{id}'` (con relleno) | denegado |
| Identificador no numérico (`'1abc'`) | denegado |

### 7.2 `BookingChangedBroadcastTest`

Para las seis transiciones:

- Se despacha exactamente **un** `BookingChanged` (`Event::assertDispatchedTimes(..., 1)`).
- `broadcastOn()[0]->name === "private-business.{$business->id}"`.
- `broadcastAs() === 'booking.changed'`.
- `change` es el valor exacto de la tabla del §3.3.

Y, además:

- **Contrato exacto del payload:** `$event->broadcastWith()` es **idéntico** a
  `['booking_id' => $booking->id, 'change' => '<valor>', 'updated_at' => $booking->updated_at->toIso8601String()]`
  — comparación de array completo, no solo de claves. Esto fija a la vez el conjunto de claves, el
  orden semántico y los valores, y **falla si alguien borra `broadcastWith()` y deja que Laravel
  serialice las propiedades públicas** (aparecería `businessId`).
- **Ausencia de datos sensibles:** con una reserva sembrada con email, teléfono, notas, precio y
  seña reconocibles, `json_encode($event->broadcastWith())` no contiene ninguno de esos valores.
- **Nada se difunde si la transacción hace rollback:** una transacción que ejecuta una transición y
  después lanza deja `Event::assertNotDispatched(BookingChanged::class)`.
- **Se difunde tras el commit de una transacción anidada:** la misma transición dentro de una
  `DB::transaction()` que sí commitea produce exactamente un evento.

### 7.3 `PaymentRealtimeIntegrationTest`

- Webhook aprobado de una seña → exactamente un `BookingChanged` con `change = 'confirmed'` sobre el
  canal del negocio de la reserva.
- `bookings:expire-unpaid` sobre una reserva con la ventana vencida → exactamente un
  `BookingChanged` con `change = 'cancelled'`.
- **Sin acoplamiento específico de pagos:** el único archivo bajo `app/Events/Broadcasting/` es
  `BookingChanged.php`. El test falla si aparece cualquier otra clase de broadcast ahí.

### 7.4 Regresión

La suite completa tiene que seguir verde. Línea base a superar: **496 tests, 1457 assertions**.
`vendor/bin/pint --test` limpio. `pnpm build` exitoso.

## 8. Contrato de runtime para el despliegue

Fase 10 agrega **un** proceso obligatorio de larga vida. Este repositorio define **qué** necesita
Reverb; el workflow externo de operaciones decide **cómo** lo implementa la máquina Linux real.

### 8.1 Procesos

```
ReservaHub
├── HTTP de Laravel        (sirve public/, incluye /broadcasting/auth)
├── worker de cola         php artisan queue:work --tries=3 --max-time=3600
├── scheduler              php artisan schedule:work
├── Reverb                 php artisan reverb:start        ← NUEVO en Fase 10
├── PostgreSQL
└── Redis
```

Reverb es obligatorio para el tiempo real y **opcional para la corrección**: sin él la aplicación
funciona entera, solo que las pantallas no se refrescan solas (§9.2).

Reinicio: Reverb mantiene el código en memoria. Cada despliegue tiene que reiniciarlo
(`php artisan reverb:restart` corta las conexiones con gracia y deja que el gestor de procesos lo
vuelva a levantar; reiniciar el contenedor equivale).

### 8.2 Variables de entorno

| Variable | Dueño | Secreto | Nota |
|---|---|---|---|
| `BROADCAST_CONNECTION` | app | no | `reverb` |
| `REVERB_APP_ID` | operador | no | Identificador de la aplicación Reverb |
| `REVERB_APP_KEY` | operador | no | Público por diseño: viaja al navegador |
| `REVERB_APP_SECRET` | operador | **sí** | Firma servidor→Reverb. **Nunca** en un `VITE_*` |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | operador | no | Dónde encuentra el **servidor** a Reverb (red interna del stack) |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | operador | no | Dónde **escucha** Reverb. `0.0.0.0` y el puerto interno |
| `REVERB_ALLOWED_ORIGINS` | operador | no | Hosts separados por coma; solo host, sin esquema ni puerto; admite `*` como comodín de `Str::is()` |
| `VITE_REVERB_APP_KEY` | operador | no | **Requerido en tiempo de build** del frontend |
| `VITE_REVERB_HOST` | operador | no | Host público que ve el navegador. **Requerido en tiempo de build** |
| `VITE_REVERB_PORT` | operador | no | Puerto público (típicamente `443`). **Requerido en tiempo de build** |
| `VITE_REVERB_SCHEME` | operador | no | `https` en producción. **Requerido en tiempo de build** |
| `FORWARD_REVERB_PORT` | app (desarrollo) | no | Solo para `compose.yaml` de desarrollo. **No** es un puerto de producción |

**Los cuatro `VITE_*` se compilan dentro del bundle.** Cambiarlos exige volver a correr `pnpm build`
y volver a desplegar `public/build`; no basta con reiniciar procesos. Eso pertenece al
procedimiento de despliegue del operador.

### 8.3 Relación con la cola

El broadcast viaja como job en la conexión de cola por defecto (`redis`, cola `default`), la misma
que ya consume el worker existente. **No hay cola nueva ni worker nuevo.** Si el worker está caído,
no hay tiempo real; las reservas y los pagos siguen siendo correctos, y los emails ya dependían de
lo mismo desde la Fase 6.

Política de reintentos, **verificada, no inventada**: el worker corre con `--tries=3`. Un job de
broadcast que no consiga hablar con Reverb se intenta tres veces y después queda registrado en
`failed_jobs` (`QUEUE_FAILED_DRIVER=database-uuids`, tabla creada en
`0001_01_01_000002_create_jobs_table.php`). Fase 10 **no** define un `tries`, `backoff` ni
`maxExceptions` propios para el broadcast: hereda la política del worker que ya existe. Un job
fallido significa que se perdió una notificación de refresco — nunca que se perdió un cambio de
reserva.

### 8.4 Requisito de proxy: tres rutas, dos destinos

El entrypoint público de ReservaHub tiene que distinguir:

| Ruta | Destino | Protocolo |
|---|---|---|
| `/app/*` | **Reverb** | WebSocket — requiere `Upgrade` / `Connection: Upgrade`, HTTP/1.1 |
| `/apps/*` | **Reverb** | HTTP normal (API de publicación del protocolo Pusher) |
| `/broadcasting/auth` | **aplicación Laravel** | HTTP normal, autenticado por sesión |
| todo lo demás | **aplicación Laravel** | HTTP normal |

La distinción importa: el proxy necesita soportar upgrade de WebSocket **para Reverb**, pero la
**autorización de canal privado sigue siendo una petición HTTP de la aplicación**, con la cookie de
sesión, pasando por el middleware `web`. No es tráfico de Reverb y no debe enrutarse ahí.

Verificado que no hay colisión: la aplicación no define ninguna ruta bajo `/app` ni `/apps`
(`routes/web.php`, `account.php`, `auth.php`, `dashboard.php`, `demo.php`, `invitations.php`,
`public.php`, `api.php`).

**Preferencia arquitectónica:** una sola frontera pública de ReservaHub capaz de servir HTTP normal
y de hacer upgrade a WebSocket. Reverb es un proceso interno de la aplicación, **no** una segunda
aplicación pública: no se modela como `realtime.example.com` salvo que un workflow de despliegue
futuro decida explícitamente lo contrario.

### 8.5 Lo que este repositorio NO decide

Hostname público final, puerto del host, configuración de `cloudflared`, YAML del túnel, DNS,
Cloudflare Access, reglas de firewall, unidades de systemd, Compose de producción, supervisión de
procesos, `ulimit`/`nofile`, rango de puertos efímeros, `ext-uv`. Nada de eso entra al repositorio y
ninguna tarea de Fase 10 lo produce.

### 8.6 Escala

Una instancia de Reverb. `REVERB_SCALING_ENABLED` queda en `false`. Sin Redis pub/sub para
broadcasting, sin multi-nodo, sin balanceador, sin estado de conexión distribuido. Redis sigue
siendo únicamente el transporte de la cola.

### 8.7 Observabilidad mínima

- **¿Está corriendo Reverb?** `docker compose ps reverb` en desarrollo; el proceso
  `php artisan reverb:start` en producción.
- **Logs:** Reverb escribe a stdout del proceso (`docker compose logs -f reverb`).
  `php artisan reverb:start --debug` imprime el flujo de mensajes; es ruidoso y se usa solo para
  diagnosticar.
- **Fallo de autorización de canal:** se ve como un `POST /broadcasting/auth` con 403 en el log de
  acceso/`storage/logs/laravel.log`, y en la consola del navegador como un error de suscripción de
  Echo.
- **Broadcast encolado fallido:** fila en `failed_jobs` con `Illuminate\Broadcasting\BroadcastEvent`
  en el payload; `php artisan queue:failed` los lista.

Sin Prometheus, sin Grafana, sin Sentry, sin Pulse, sin monitoreo externo.

## 9. Invariantes duras

### 9.1 Solo se difunde estado comprometido

`ShouldDispatchAfterCommit` (§3.5). El navegador nunca recibe "la reserva cambió" por una
transacción que después hizo rollback. Test: §7.2.

### 9.2 La corrección del dominio no depende de Reverb

Reverb caído, socket cortado, job de broadcast fallido: la reserva se guarda, el pago se aplica, el
historial se escribe y un refresh normal muestra el estado canónico. La petición HTTP de dominio
nunca abre una conexión a Reverb (§3.6). El tiempo real es una mejora, nunca un requisito.

Verificación manual obligatoria: §9.5.

### 9.3 El tiempo real no amplía ningún permiso

El callback del canal es la unión exacta de las condiciones que HTTP ya exige (§5.2), y el payload
no contiene ningún dato de negocio (§3.4). Tests: §7.1 y §7.2.

### 9.4 Smoke local de dos navegadores

Sin Cloudflare, sin DNS, sin dominio público, sin túnel. Todo contra el stack de Docker local.

```
# preparación
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan db:seed --class=DemoSeeder
docker compose exec laravel.test bash -lc "pnpm install --frozen-lockfile && rm -f public/hot && pnpm build"
docker compose ps reverb        # tiene que estar Up
```

1. **Navegador A** — sesión de `owner@reservahub.test`, abrir `/dashboard/bookings`. La consola no
   muestra errores de Echo (el WebSocket a `localhost:${FORWARD_REVERB_PORT}` conecta).
2. **Navegador B** (ventana privada) — sesión de un cliente, reservar en la página pública del
   negocio.
3. **Esperado en A:** la fila nueva aparece sola, sin refresh manual.
4. **Navegador B** — pagar la seña por el checkout simulado y aprobarla.
5. **Esperado en A:** la fila pasa de `Pendiente` a `Confirmada` sola, sin refresh manual.
6. **Navegador A** — pulsar `Completar` en una reserva confirmada desde otra pestaña de staff.
   **Esperado:** la primera pestaña se actualiza sola.
7. **Aislamiento entre negocios:** un tercer navegador con sesión de staff de **otro** negocio,
   abierto en `/dashboard/bookings`, **no** se actualiza por nada de lo anterior.

### 9.5 Smoke local de fallo de Reverb

Demuestra el §9.2 de forma reproducible:

1. `docker compose stop reverb`.
2. En el navegador A, ejecutar una transición de reserva (por ejemplo, `Confirmar`).
3. **Esperado:** la acción HTTP tiene éxito con normalidad — sin error, sin timeout, sin 500.
4. Verificar el estado comprometido en PostgreSQL:
   `docker compose exec laravel.test php artisan tinker --execute="echo \App\Models\Booking::withoutGlobalScopes()->find(<id>)->status->value;"`
   devuelve el estado nuevo.
5. Un refresh manual de la página muestra el estado canónico.
6. `docker compose start reverb`. Las transiciones siguientes vuelven a actualizar la pantalla sola.

Tras el paso 3 puede quedar una fila en `failed_jobs` con `BroadcastEvent`: es exactamente el
resultado esperado y no afecta al dominio (§8.3).

## 10. Documentación que esta fase actualiza

- **Esta especificación** — `docs/superpowers/specs/2026-08-20-fase10-tiempo-real-design.md`.
- **`docs/DEPLOYMENT_HANDOFF.md`** — Reverb como cuarto proceso obligatorio; contrato de variables
  `REVERB_*` y `VITE_REVERB_*` con el secreto marcado como server-only; requisito de proxy con la
  tabla de tres rutas del §8.4; `BROADCAST_CONNECTION` pasa de `log` a `reverb`; nota de reinicio;
  logs de Reverb. **Dice qué necesita Reverb, nunca cómo lo provee el host Linux.**
- **`CLAUDE.md`** — sección de Fase 10; el contenedor `reverb` y su necesidad de reinicio;
  `FORWARD_REVERB_PORT` en la lista de puertos que hay que cambiar al levantar un stack de worktree
  en paralelo; los dos smokes locales (§9.4 y §9.5).
- **`01-reservahub.md`** — la fila 10 de la tabla de estado pasa a *Hecha* con su evidencia.

## 11. Criterios de aceptación

1. Reverb corre localmente en el entorno del proyecto (`docker compose ps reverb` = Up).
2. Cada una de las seis transiciones aprobadas emite exactamente una señal de tiempo real.
3. La señal se emite solo después del commit; un rollback no emite nada.
4. Un cliente de otro negocio no puede suscribirse ni recibir la señal.
5. La autorización por rol del canal coincide exactamente con la autorización HTTP vigente.
6. No se difunde ninguna información sensible de reserva, cliente ni pago.
7. `Dashboard/Bookings/Index` se actualiza sin refresh manual.
8. La aprobación de un pago llega a esa pantalla por la transición de dominio normal, sin código de
   tiempo real específico de pagos.
9. La cancelación automática por seña impaga llega por la misma vía.
10. Reconexión y refresh manual restauran el estado canónico.
11. Reverb no disponible no rompe la escritura de reservas (§9.5 verificado a mano).
12. La suite completa de backend sigue verde (≥ 496 tests) y Pint limpio.
13. `pnpm build` termina con éxito.
14. El smoke local de dos navegadores (§9.4) pasa.
15. El desarrollo local no requiere Cloudflare en ningún paso.
16. El handoff de despliegue documenta Reverb sin ejecutar ni decidir despliegue de producción.

## 12. Frontera con las fases siguientes

**Fase 11 (rediseño frontend)** — esta fase **no** hace: landing nueva, dashboard nuevo, sistema de
diseño, rediseño responsive, componente o librería de calendario, badges de tiempo real, toasts,
animaciones, indicador visual de conexión, ni arquitectura de estado global. El cambio de UI de
Fase 10 es una línea de JSX y un componente que renderiza `null`. Si Fase 11 decide construir un
calendario, se suscribe al mismo `BookingChanged` sin cambiar nada del backend. Si decide agregar
tiempo real para el cliente, agrega un segundo tipo de canal de forma aditiva.

**Fase 12 (release readiness y handoff)** — esta fase **no** hace: CI, despliegue, configuración de
Cloudflare, aprovisionamiento de Linux, systemd, firewall, backups, secretos de producción ni
Compose de producción. Solo actualiza `docs/DEPLOYMENT_HANDOFF.md`, porque Reverb cambia el contrato
de runtime de la aplicación.
