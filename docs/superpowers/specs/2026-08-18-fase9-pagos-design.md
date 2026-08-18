# Fase 9 — Pagos (diseño)

Fecha: 2026-08-18
Estado: aprobado en estructura, pendiente de plan de implementación.
Rama: `feat/phase-9-payments`

## 1. Objetivo

Cerrar el punto 9 del roadmap: contrato `PaymentGateway`, proveedor simulado de primera clase,
persistencia de eventos de webhook, verificación de firma, idempotencia, job de reconciliación y
tests con payloads simulados. Una reserva que requiere seña no puede confirmarse hasta que el pago
esté aprobado, y un webhook repetido nunca puede duplicar un pago ni una confirmación.

**No hay proveedor de pago real en esta fase.** El único proveedor es `simulated`, y la arquitectura
tiene que funcionar completa con él. Un adapter real futuro reemplaza un binding de contenedor sin
tocar el dominio.

Fuera de alcance, explícito: suscripciones, facturación recurrente, planes SaaS, facturas, impuestos,
integraciones fiscales, reembolsos, contracargos, cupones, wallets, split payments, marketplace,
pagos a empleados, tarjetas guardadas, almacenamiento PCI, contabilidad, conversión multi-moneda,
analítica de pagos, purga automática de `webhook_events`, Reverb (Fase 10), rediseño frontend
(Fase 11) y CI/despliegue (Fase 12).

## 2. Punto de partida verificado

Verificado contra el código y una corrida completa de la suite (358 tests, 0 fallos, commit `0b0ffc9`)
antes de escribir este documento:

- `App\Actions\Bookings\CreateBooking` **ya** decide el estado por seña:
  `$status = $service->deposit_amount > 0 ? BookingStatus::Pending : BookingStatus::Confirmed`, y
  copia `price` y `deposit_amount` del servicio a la reserva. La regla "reserva con seña queda
  pendiente" ya existe; lo que falta es el pago que la saque de ahí.
- `App\Actions\Bookings\ConfirmBooking` exige `BookingStatus::Pending`, escribe
  `BookingStatusHistory` y dispara `BookingConfirmed`; su listener
  `SendBookingConfirmedNotifications` es `ShouldQueue`. Es el único camino de confirmación y no se
  duplica en esta fase.
- `booking_status_histories.changed_by` es **nullable** con `nullOnDelete()`: una transición sin actor
  humano ya es representable en el esquema.
- `App\Services\AvailabilityService` (línea ~109) considera ocupadas las reservas en
  `[Pending, Confirmed, Completed]`. **Una reserva `pending` bloquea el slot**: una reserva con seña
  nunca pagada bloquea el turno indefinidamente. Este agujero es anterior a los pagos y esta fase lo
  cierra.
- `App\Actions\Bookings\CancelBooking` aplica el corte de `cancellation_hours` **solo** cuando
  `$actingUser->role === Role::Customer`; `BookingCancelled` exige `User $cancelledBy` no-nulo y
  `BookingCancelledNotification::cancelledByCustomer()` lo desreferencia sin protección.
- Convenciones de concurrencia existentes: `pg_advisory_xact_lock(hashtext(...))` dentro de
  `DB::transaction` (`CreateBooking`, `RescheduleBooking`), `lockForUpdate` con orden fijo por `id`
  (`SetUserActiveStatus`), claim por unicidad con `insertOrIgnore` (`SendBookingReminders`), y tests
  de concurrencia con **dos sesiones PDO reales** + `DatabaseMigrations`
  (`tests/Unit/Database/AdvisoryLockTest.php`, `tests/Feature/Dashboard/UserStatusConcurrencyTest.php`).
- Tenancy: `App\Models\Scopes\BusinessScope` lanza `MissingBusinessContextException` fuera de consola
  cuando no hay negocio ligado. Una petición de webhook no tiene negocio ligado, así que toda
  consulta del pipeline de webhook debe levantar el scope explícitamente y derivar el negocio desde
  la fila de pago.
- `App\Http\Controllers\Api\Concerns\ResolvesBookingScope` ya resuelve el caso mixto staff/cliente
  para reservas; los pagos lo reutilizan en vez de inventar otra resolución.
- Envelope obligatorio: `App\Support\ApiResponse` (`{success, data, message, errors}`), con las
  excepciones mapeadas en `bootstrap/app.php`.
- Scheduler: `routes/console.php` con `Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping(10)`,
  ejecutado por el contenedor `scheduler`. Cola: Redis en runtime, `sync` en tests (`phpunit.xml`).
- No existe `app/Jobs/`; no existe `app/Services/Payments/`; no hay tablas `payments` ni
  `webhook_events`.
- Framework instalado: `Illuminate\Events\Dispatcher` (líneas 722-724) propaga la propiedad
  `afterCommit` de un listener al job encolado, y `Illuminate\Queue\Queue` (399-403) la respeta por
  job. El comportamiento *after commit* se resuelve **por listener**, sin tocar la conexión de cola.
- Rutas: `routes/web.php` hace `require` de `account.php`, `auth.php`, `dashboard.php`,
  `invitations.php` y `public.php`. El área de cliente vive bajo `mis-reservas`
  (`public.bookings.mine.*`); el panel bajo `dashboard` con middleware `['auth','business']`.
- Monedas: enum `App\Enums\Currency` (ISO-4217 acotado); `businesses.currency` es string.
  `services.price`, `services.deposit_amount`, `bookings.price`, `bookings.deposit_amount` son
  `decimal(10,2)` con cast `decimal:2` (devuelven string).

## 3. Decisiones de diseño aprobadas

1. Proveedor: **solo simulado** (`simulated`). Sin SDK, sin credenciales externas, sin URL pública
   entrante.
2. La iniciación del pago es un **paso explícito posterior a la reserva**; `CreateBooking` no hace I/O
   de proveedor.
3. Una reserva puede acumular **varios intentos**, pero **como máximo uno activo** (`pending`),
   garantizado por un índice único parcial de PostgreSQL.
4. Estados internos monótonos: `pending → approved | rejected | expired`; los tres finales son
   terminales y no admiten transición posterior.
5. Una aprobación tardía **nunca resucita** una reserva que ya no está `pending`: el pago se registra
   fielmente, la reserva no se toca.
6. La **expiración es propiedad de la reserva**, no del pago: `bookings.payment_expires_at`.
7. Un solo borde de procesamiento (`ProcessPaymentWebhook`) y un solo camino de aplicación
   (`ApplyPaymentResult`), compartidos por el endpoint HTTP, la entrega simulada interna y la
   reconciliación.
8. `PaymentGateway` es provider-neutral: ningún tipo de Laravel ni de SDK cruza el contrato.
9. UI mínima y honesta: checkout **simulado**, rotulado, sin ningún campo de tarjeta.

## 4. Ciclo de vida del pago

`App\Enums\PaymentStatus`: `pending`, `approved`, `rejected`, `expired`.

| Desde | Hacia | Terminal | Efecto sobre la reserva | Persistencia |
|---|---|---|---|---|
| `pending` | `approved` | sí | `ConfirmBooking` **si** la reserva sigue `pending`; si no, ninguna | `paid_at`, `applied_at`, `application_outcome`, `last_snapshot` |
| `pending` | `rejected` | sí | ninguna | `failure_reason`, `last_snapshot` |
| `pending` | `expired` | sí | ninguna (la ventana de la reserva libera el slot aparte) | `last_snapshot` |
| terminal | cualquiera | — | ninguna | ninguna: no-op registrado en el evento (`payment_already_terminal` / `illegal_transition`) + log warning |

**Estados monótonos.** No existe `expired → approved`: el proveedor simulado tiene su propio ciclo
monótono y **rechaza aprobar después de su expiración**, de modo que una aprobación tardía siempre es
`local pending → approved`. Un evento que pretenda mover un pago terminal se registra y se descarta.

`App\Enums\PaymentApplicationOutcome`: `booking_confirmed`, `booking_not_pending`, `no_action`.
Se persiste en `payments.application_outcome` y se refleja en `webhook_events.outcome_reason`.

**Confirmación manual del staff con un intento vivo.** Está permitida y no toca al proveedor. Si luego
llega `approved`, el pago pasa a `approved` con `paid_at` y `last_snapshot` (registro fiel), pero
`applied_at = null` y `application_outcome = 'booking_not_pending'`, porque la reserva ya no está
`pending`. No se cancela el intento del proveedor ni se genera reembolso alguno (fuera de alcance).

## 5. Contrato `PaymentGateway`

`app/Services/Payments/Contracts/PaymentGateway.php`:

```php
namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\CheckoutResult;
use App\Services\Payments\Data\ProviderSnapshot;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\Data\WebhookNotification;
use DateTimeImmutable;

interface PaymentGateway
{
    /** Identificador estable del proveedor; se persiste en `payments.provider`. */
    public function name(): string;

    /** @throws \App\Services\Payments\Exceptions\GatewayUnavailableException */
    public function createCheckout(CheckoutRequest $request): CheckoutResult;

    /**
     * URL efímera de checkout para un intento vivo. Se genera en cada respuesta,
     * nunca se persiste.
     */
    public function checkoutUrl(string $externalId, DateTimeImmutable $expiresAt): string;

    /**
     * @throws \App\Services\Payments\Exceptions\InvalidWebhookSignatureException
     * @throws \App\Services\Payments\Exceptions\MalformedWebhookPayloadException
     */
    public function parseWebhook(WebhookEnvelope $envelope): WebhookNotification;

    /**
     * @throws \App\Services\Payments\Exceptions\GatewayUnavailableException
     * @throws \App\Services\Payments\Exceptions\UnknownProviderPaymentException
     */
    public function fetchPayment(string $externalId): ProviderSnapshot;
}
```

Binding, sin manager ni selección dinámica en runtime (`AppServiceProvider::register()`):

```php
$this->app->bind(PaymentGateway::class, fn () => new SimulatedPaymentGateway(
    secret: config('payments.simulated.webhook_secret'),
    toleranceSeconds: config('payments.webhook_tolerance_seconds'),
));
```

**Ninguna clase de dominio conoce códigos HTTP.** El segmento `{provider}` de la ruta se compara en el
controller contra `app(PaymentGateway::class)->name()`; si no coincide, el controller devuelve 404.

### DTOs (`app/Services/Payments/Data/`, todos `final readonly`)

| DTO | Campos |
|---|---|
| `CheckoutRequest` | `int $paymentId`, `string $amount`, `string $currency`, `string $description`, `string $returnUrl`, `DateTimeImmutable $expiresAt` |
| `CheckoutResult` | `string $externalId`, `PaymentStatus $status`, `DateTimeImmutable $expiresAt`, `array $snapshot` (redactado) |
| `WebhookEnvelope` | `string $rawBody`, `array $headers` (claves en minúscula) |
| `WebhookNotification` | `string $eventId`, `string $externalPaymentId`, `PaymentStatus $status`, `string $amount`, `string $currency`, `DateTimeImmutable $occurredAt`, `?string $failureReason`, `array $payload` (redactado) |
| `ProviderSnapshot` | `string $externalId`, `PaymentStatus $status`, `string $amount`, `string $currency`, `?DateTimeImmutable $occurredAt`, `?string $failureReason`, `array $payload` (redactado) |
| `PaymentResult` | `PaymentStatus $status`, `string $amount`, `string $currency`, `?DateTimeImmutable $occurredAt`, `array $snapshot`, `?string $failureReason` |

`PaymentResult` es la **entrada única** de `ApplyPaymentResult`: se construye desde una
`WebhookNotification` (camino webhook) o desde un `ProviderSnapshot` (camino reconciliación). Ninguna
de las dos rutas tiene lógica de aplicación propia.

`CheckoutResult.checkoutUrl` **no existe**: la URL es efímera y se pide con `checkoutUrl()` al
construir la respuesta. No se persiste ninguna URL atada al entorno.

### Excepciones (`app/Services/Payments/Exceptions/`)

`InvalidWebhookSignatureException`, `MalformedWebhookPayloadException`, `GatewayUnavailableException`,
`UnknownProviderPaymentException`.

## 6. Proveedor simulado

`app/Services/Payments/Simulated/SimulatedPaymentGateway.php`, `name() === 'simulated'`.

**Estado propio e independiente**, tabla `simulated_provider_payments`. El adapter **nunca** lee
`payments`: si lo hiciera, la reconciliación compararía una fila consigo misma y no probaría nada.

- `createCheckout()`: genera `external_id` (`sim_pay_<ulid>`), inserta la fila del proveedor en
  `pending` con `expires_at` y monto/moneda recibidos, y devuelve `CheckoutResult`.
- `checkoutUrl()`: `URL::temporarySignedRoute('demo.payments.checkout', $expiresAt, ['payment' => …])`
  — fresca en cada llamada, con expiración **no posterior** a `payment_expires_at`.
- `parseWebhook()`: verifica firma y tolerancia contra el `rawBody` exacto, decodifica y **redacta**.
- `fetchPayment()`: lee `simulated_provider_payments`; si la fila venció y sigue `pending`, la marca
  `expired` (expiración del lado del proveedor) y devuelve ese estado. `UnknownProviderPaymentException`
  si el `external_id` no existe. Puede simular caída con `GatewayUnavailableException` (ver §20).

**Ciclo de vida del proveedor simulado**, monótono: `pending → approved`, `pending → rejected`,
`pending → expired`. Aprobar o rechazar después de la expiración del proveedor se rechaza en el propio
adapter. Simula: `pending`, `approved`, `rejected`, entrega duplicada (mismo `event_id` entregado dos
veces), firma inválida (secreto distinto), payload ilegible, expiración, caída de proveedor para
reconciliación. **Cero ramas condicionales por proveedor en el dominio.**

## 7. Esquema

### `payments`

```
id                      bigIncrements
business_id             FK businesses  cascadeOnDelete
booking_id              FK bookings    cascadeOnDelete
provider                string
external_id             string                 NOT NULL
status                  string                 PaymentStatus
amount                  decimal(10,2)          snapshot de bookings.deposit_amount
currency                char(3)                snapshot de businesses.currency
expires_at              timestamp              = bookings.payment_expires_at
paid_at                 timestamp nullable
applied_at              timestamp nullable
application_outcome     string    nullable     PaymentApplicationOutcome
failure_reason          string    nullable     motivo saneado del rechazo
last_snapshot           jsonb     nullable     último estado del proveedor, redactado
last_reconcile_attempt_at timestamp nullable   cadencia de intento (éxito o fallo)
last_reconciled_at      timestamp nullable     último fetch exitoso
timestamps
```

| Índice | Invariante |
|---|---|
| `unique (provider, external_id)` | un pago del proveedor ↔ una fila local |
| `unique (booking_id) where status = 'pending'` | **como máximo un intento activo por reserva** |
| `index (status, last_reconcile_attempt_at)` | elegibilidad de reconciliación |
| `index business_id`, `index booking_id` | tenancy y relaciones |

`external_id` es NOT NULL porque la iniciación ocurre en **una sola transacción** (§9): nunca existe
una fila local sin identidad externa, y por eso un webhook que referencia un pago desconocido es
genuinamente anómalo.

Modelo `App\Models\Payment` con `BelongsToBusiness` (scope global de negocio), `belongsTo(Booking)`,
casts `status => PaymentStatus`, `amount => decimal:2`, `last_snapshot => array`, fechas a `datetime`.

### `webhook_events`

Tabla global de auditoría e identidad: **sin `business_id`**, sin `BelongsToBusiness`, sin API ni UI.

```
id                  bigIncrements
provider            string
external_event_id   string
payment_external_id string  nullable
payload             jsonb            redactado por lista blanca
status              string           WebhookEventStatus: received|processed|ignored|failed
outcome_reason      string  nullable
attempts            unsignedInteger default 0
last_error          text    nullable  mensaje truncado; nunca payload, firma ni secreto
received_at         timestamp
processed_at        timestamp nullable
timestamps

unique (provider, external_event_id)
index  (status, received_at)
```

`App\Enums\WebhookEventStatus`:

| Estado | Significado | ¿Reprocesable? |
|---|---|---|
| `received` | reclamado, sin terminar (p. ej. el proceso murió a mitad) | **sí** |
| `processed` | resultado del proveedor aplicado con éxito al pago (aunque la reserva no fuese elegible) | no |
| `ignored` | resultado inusable/obsoleto/inválido que a propósito no muta el pago | no |
| `failed` | error transitorio o interno; `attempts++`, `last_error` | **sí** |

`outcome_reason` posible: `booking_confirmed`, `booking_not_pending`, `duplicate`, `amount_mismatch`,
`currency_mismatch`, `payment_already_terminal`, `illegal_transition`, `unknown_payment`,
`internal_error`.

**`booking_not_pending` es `processed`**, no `ignored`: el resultado del proveedor se aplicó
correctamente al pago; lo que no correspondía era mutar la reserva. `ignored` queda reservado a
resultados que a propósito no mutan el pago.

### `simulated_provider_payments`

```
id, external_id (unique), status, amount decimal(10,2), currency char(3),
approved_at timestamp nullable, expires_at timestamp, payload jsonb, timestamps
```

Estado del proveedor simulado. **No es dato de dominio**: ninguna consulta de negocio, Policy, Resource
ni Action la lee; solo el adapter.

### `bookings` (columna nueva)

```
payment_expires_at  timestamp nullable
```

La escribe `CreateBooking` **solo** cuando `deposit_amount > 0`:
`min(now() + config('payments.window_minutes'), starts_at)` — el clamp evita una ventana que termine
después del turno. Sin seña queda `null` y el flujo actual no cambia en nada.

**Semántica de `null`:** significa "esta reserva no tiene obligación de pago". Una reserva `pending`
con `deposit_amount > 0` y `payment_expires_at = null` **no puede iniciar pago** (422) y **nunca es
cancelada automáticamente**; es un estado que solo puede existir por datos anteriores a esta fase.
Para que no queden reservas atrapadas, la migración **rellena** `payment_expires_at = min(now() +
window_minutes, starts_at)` en las reservas `pending` existentes con `deposit_amount > 0`, dándoles
una ventana fresca desde el despliegue en vez de una ya vencida (rellenar con `created_at + window`
provocaría una cancelación masiva en el primer barrido).

**Reprogramación:** `RescheduleBooking` **nunca extiende** la ventana; solo la ajusta hacia abajo si el
turno nuevo empieza antes: `payment_expires_at = min(payment_expires_at, nuevo starts_at)`. Reprogramar
no es una vía para renovar indefinidamente una obligación de pago vencida. La ventana de la reserva es
la autoridad para `expire-unpaid`; el `expires_at` del pago solo gobierna la expiración del lado del
proveedor.

## 8. Ventana de pago y cancelación automática

### Comando `bookings:expire-unpaid`

Scheduler existente, `everyFiveMinutes()->withoutOverlapping(10)`. Por cada reserva candidata, dentro
de una transacción y con la fila de `bookings` bloqueada (`lockForUpdate`), re-verifica y decide:

| Situación tras `payment_expires_at <= now()` | Acción |
|---|---|
| Reserva no `pending` | nada |
| **Ningún** intento de pago | cancelar |
| Todos los intentos terminales y **ninguno** `approved` | cancelar |
| Algún intento `pending` | **no cancelar**: esperar a que la reconciliación resuelva la verdad del proveedor |
| Existe algún pago `approved` | **nunca** cancelar |

Con el proveedor caído, el slot queda bloqueado; es preferible a cancelar una reserva que quizá ya fue
pagada. Una reserva ya cancelada jamás se resucita.

### Cancelación del sistema, explícita

`null` no es un bypass mágico. Se introduce `App\Enums\CancellationReason`:

```php
enum CancellationReason: string
{
    case Requested = 'requested';                          // humano: cliente o staff
    case PaymentWindowExpired = 'payment_window_expired';  // sistema
}
```

`CancelBooking::handle(Booking $booking, ?User $actingUser, CancellationReason $reason = CancellationReason::Requested)`:

- `Requested`: exige `$actingUser` no-nulo (si no, `InvalidArgumentException`), y conserva **exactamente**
  el comportamiento actual, incluido el corte de `cancellation_hours` cuando el actor es cliente.
- `PaymentWindowExpired`: exige `$actingUser === null` (si no, `InvalidArgumentException`), exige que la
  reserva esté `pending`, y **omite deliberadamente el corte de `cancellation_hours`** — el corte
  protege al negocio de cancelaciones tardías del cliente, no aplica a una expiración del sistema.
  La omisión está documentada en el código y probada.

Efectos comunes: `status = cancelled`, `cancelled_at = now()`, `BookingStatusHistory` con
`changed_by = null` y `notes = 'Cancelación automática: la seña no se pagó dentro del plazo.'`, y el
evento existente `BookingCancelled`.

`BookingCancelled` pasa a `(Booking $booking, ?User $cancelledBy, CancellationReason $reason)`.
`SendBookingCancelledNotifications` gana `public bool $afterCommit = true;` y propaga el motivo.
`BookingCancelledNotification` acepta `?User $cancelledBy`, protege `cancelledByCustomer()` con `?->`
y añade una rama de copy para el actor sistema:

- Cliente: *"Se canceló tu reserva de {servicio} del {fecha} porque no se registró el pago de la seña
  dentro del plazo."*
- Empleado: *"Se canceló automáticamente la reserva de {cliente} para {servicio} del {fecha}: la seña
  no se pagó dentro del plazo."*
- `toArray()` incorpora `'reason' => $reason->value`.

## 9. Iniciación del pago

`App\Actions\Payments\InitiatePayment::handle(Booking $booking, User $actingUser): Payment`

Una sola transacción, con la **fila de `bookings` como límite de serialización** (sin advisory lock:
no hay invariante que el lock de fila no cubra):

```
BEGIN
  SELECT ... FROM bookings WHERE id = ? FOR UPDATE
  re-chequeos:
    status === pending                          si no → ValidationException (422)
    deposit_amount > 0                          si no → ValidationException (422)
    payment_expires_at > now()                  si no → ValidationException (422)
  ¿existe pago pending para esta reserva?       sí → devolverlo (idempotente)
  INSERT payments (..., status=pending, amount=booking.deposit_amount,
                   currency=business.currency, expires_at=booking.payment_expires_at)
  gateway->createCheckout(CheckoutRequest)      → external_id, snapshot
  UPDATE payments SET external_id, last_snapshot
COMMIT
```

El índice único parcial `(booking_id) where status='pending'` es la defensa en profundidad: dos
iniciaciones concurrentes se serializan por el lock de la reserva y, si algo se colara, la base
rechaza la segunda.

**La reserva vencida no puede iniciar ni reintentar pago aunque el comando periódico todavía no la
haya procesado** — esto cierra la carrera del intervalo del scheduler.

Si `createCheckout()` falla, la transacción entera vuelve atrás: no queda fila local ni fila de
proveedor.

*Límite conocido:* el adapter simulado escribe su estado en la misma base y por eso puede vivir dentro
de la transacción. Un adapter real **no** puede hacer HTTP ahí dentro: necesitaría una referencia /
idempotency-key generada por la aplicación y una fase de confirmación. Queda documentado y fuera de
alcance.

## 10. Borde de procesamiento del webhook

`App\Services\Payments\ProcessPaymentWebhook::handle(WebhookEnvelope $envelope): WebhookProcessingResult`

Dos entradas, un solo borde:

```
SimulatedPaymentGateway  → payload + firma
  → DeliverSimulatedProviderWebhook (job, en proceso, sin HTTP)
                                                    ┐
POST /api/webhooks/payments/{provider}              ├→ ProcessPaymentWebhook
  → WebhookController (fino)                        │
  → WebhookEnvelope(rawBody, headers)               ┘
```

Pasos:

1. **Firma y forma**: `gateway->parseWebhook($envelope)` verifica HMAC contra el `rawBody` exacto y la
   tolerancia temporal. `InvalidWebhookSignatureException` / `MalformedWebhookPayloadException` salen
   acá, **sin persistir nada**.
2. **Identidad** (transacción propia): `insert ... on conflict do nothing` en `webhook_events` con
   `status='received'`, `attempts=0`, `payload` redactado.
3. **Proceso** (una sola transacción):
   - `SELECT ... FOR UPDATE` de la fila de `webhook_events` y **re-lectura del estado dentro del lock**.
     `processed`/`ignored` → `Duplicate`, cero efectos. `received`/`failed` → procesar.
   - Buscar el pago por `(provider, external_id)`, **levantando `BusinessScope`**; ausente →
     `status='failed'`, `outcome_reason='unknown_payment'`, `attempts++` → `Failed`.
   - Validar monto y moneda contra la fila local (§17). Discrepancia → `status='ignored'` con
     `amount_mismatch` / `currency_mismatch` → `Ignored`.
   - `ApplyPaymentResult` (§11) y el flip del evento a `processed`/`ignored` **commitean juntos**.
4. **Fallo**: rollback de todo (ni efecto ni marca de hecho) y, en una transacción separada
   best-effort, `status='failed'`, `attempts++`, `last_error` (mensaje truncado, sin payload ni firma).

Por qué no alcanza `insertOrIgnore` solo: si el proceso muere entre el insert y el efecto, el reintento
del proveedor vería "duplicado" y el evento quedaría muerto para siempre. El claim con estado +
lock de fila + efecto atómico cumple los dos invariantes: **el mismo evento externo nunca produce el
efecto dos veces**, y **un fallo transitorio no vuelve el evento imposible de procesar**.

Red de seguridad final: aunque el proveedor no reintente nunca, la reconciliación repara el *pago*
consultando el estado independiente del proveedor.

`App\Enums\WebhookProcessingStatus`: `processed`, `duplicate`, `ignored`, `failed`.
`WebhookProcessingResult` = `{WebhookProcessingStatus $status, ?string $reason}`.

## 11. Aplicación del resultado

`App\Actions\Payments\ApplyPaymentResult::handle(Payment $payment, PaymentResult $result): PaymentApplicationOutcome`

Camino **único** para webhook y reconciliación. Dentro de una transacción, con el **orden de bloqueo
global `webhook_events → bookings → payments`**: lee `payment.booking_id` sin lock, bloquea la reserva,
bloquea el pago, y recién ahí re-lee estados.

```
si la transición status→result.status no es legal (§4):
    → no-op; outcome_reason = payment_already_terminal | illegal_transition; log warning

approved:
    payments: status=approved, paid_at=result.occurredAt, last_snapshot
    si booking.status === pending → ConfirmBooking(booking, actingUser: null)
        applied_at=now(), application_outcome=booking_confirmed
    si no → applied_at=null, application_outcome=booking_not_pending, log warning

rejected:
    payments: status=rejected, failure_reason, last_snapshot
    application_outcome=no_action; reserva intacta

expired:
    payments: status=expired, last_snapshot
    application_outcome=no_action; reserva intacta
```

`ConfirmBooking` recibe actor `null` (requiere ampliar su firma a `?User` y escribir
`changed_by = null` con `notes = 'Confirmada por pago aprobado #<payment_id>.'`). Sigue siendo el
**único** camino de confirmación: ni el controller ni el job de reconciliación mutan `Booking`.

`SendBookingConfirmedNotifications` gana `public bool $afterCommit = true;` para que un rollback no
deje encolado un email de confirmación. (Con driver `sync` en tests corre inline, como el resto.)

## 12. Reconciliación

`App\Console\Commands\ReconcilePayments`, firma `payments:reconcile`, en el scheduler existente:
`everyFiveMinutes()->withoutOverlapping(10)`. Sin contenedor ni cron nuevos, sin cola.

Problema que resuelve: la entrega del evento puede perderse o fallar de forma permanente, y el
proveedor es la única autoridad sobre el dinero.

Elegibilidad (trabajo acotado por estado + cadencia + lote, **sin ventana por antigüedad**):

```sql
status = 'pending'
AND (last_reconcile_attempt_at IS NULL
     OR last_reconcile_attempt_at <= now() - INTERVAL '<cadence_minutes> minutes')
ORDER BY last_reconcile_attempt_at NULLS FIRST, created_at
LIMIT <batch>
```

Un pago viejo todavía pendiente es exactamente la divergencia que hay que reparar, así que no se lo
excluye por antigüedad. Los pagos terminales salen solos del working set.

**Cadencia de intento ≠ estado de reconciliación exitosa** (anti-inanición):

- Antes de llamar a `fetchPayment`: `last_reconcile_attempt_at = now()` (se persiste sí o sí).
- Fetch exitoso: además `last_reconciled_at = now()`.
- `GatewayUnavailableException`: `last_reconciled_at` **no** cambia; log warning; sigue el lote.

Así un subconjunto que falla siempre no puede monopolizar todas las corridas ni impedir que el resto
del lote sea inspeccionado.

Por cada pago: `fetchPayment(external_id)` → `ProviderSnapshot` → `PaymentResult` → **el mismo**
`ApplyPaymentResult`. Cero lógica de aplicación duplicada. Estado ya terminal → no-op por el guard de
transición. `UnknownProviderPaymentException` → log warning, sin cambio de estado. La reconciliación
**nunca** toca `webhook_events`.

Cadena demostrable de abandono: el cliente abandona el checkout → el proveedor simulado expira solo →
la reconciliación trae `expired` → el pago queda terminal → `bookings:expire-unpaid` cancela la reserva
y libera el slot.

## 13. Colas

| Pieza | ¿Encolada? | Configuración |
|---|---|---|
| `App\Jobs\DeliverSimulatedProviderWebhook` | sí | `tries=3`, `backoff=[10,30]`, `timeout=15`, `public bool $afterCommit = true` |
| `SendBookingConfirmedNotifications`, `SendBookingCancelledNotifications` | ya lo estaban | se les añade `$afterCommit = true` |
| `payments:reconcile`, `bookings:expire-unpaid` | **no** | corren en el proceso del scheduler; lote acotado |
| Procesamiento HTTP del webhook | **no** | síncrono: encolarlo devolvería 200 antes de saber si se pudo procesar y desperdiciaría el reintento del proveedor |

**La firma se genera dentro de `handle()`**, no al encolar: `occurredAt` (cuándo ocurrió el evento del
proveedor) y el timestamp de firma `t` (cuándo se intenta *esta* entrega) son campos distintos. Un
retraso de cola mayor que `PAYMENTS_WEBHOOK_TOLERANCE_SECONDS` haría que el proveedor rechazara su
propia entrega legítima. Un reintento conserva `external_event_id` y el evento lógico, con `t` y HMAC
frescos.

El job **no hace HTTP**: construye el mismo `WebhookEnvelope` que construiría el controller y entra al
mismo pipeline. Sin DNS, sin Cloudflare, sin túnel, sin `APP_URL`, sin nombres de servicios Docker.

## 14. API

Envelope `ApiResponse` en todas las respuestas. Rutas compartidas staff/cliente, **sin** middleware
`business`, reutilizando `ResolvesBookingScope` (staff liga su negocio; cliente levanta `BusinessScope`
filtrando por `customer_id`).

| Ruta | Auth | Respuesta |
|---|---|---|
| `POST /api/bookings/{booking}/payments` | `auth:sanctum` | 201 con el intento nuevo; **200** con el intento vivo si ya existe uno `pending` |
| `GET /api/bookings/{booking}/payments` | `auth:sanctum` | listado de intentos (histórico) |
| `GET /api/payments/{payment}` | `auth:sanctum` | detalle |
| `POST /api/webhooks/payments/{provider}` | ninguna | `throttle:payment-webhooks` (120/min por IP) |

Desvío consciente del roadmap §5 (`POST /api/payments`): la reserva es contexto obligatorio y anidar
reutiliza la resolución de scope existente. Se documenta el motivo en `01-reservahub.md`.

Errores de iniciación (422 con envelope): reserva sin seña, reserva no `pending`, ventana vencida.
Autorización fallida: 403; recurso de otro negocio/cliente: 404 o 403 según el patrón actual de
reservas.

`App\Http\Resources\PaymentResource`: `id`, `status`, `amount`, `currency`, `expires_at`, `paid_at`,
`application_outcome`, `failure_reason`, `created_at`, y `checkout_url` **solo** cuando el pago está
`pending` y el actor puede iniciarlo (generada al vuelo con `gateway->checkoutUrl()`). **Nunca**
`last_snapshot`, nunca payloads crudos, nunca `webhook_events`.

## 15. Web y checkout simulado

- Cliente: botón "Pagar seña" en Mis reservas → `POST /mis-reservas/{booking}/pagos`
  (`public.bookings.mine.payments.store`) → redirect a la URL de checkout.
- Panel: estado del pago en el detalle de reserva y botón de generación del intento →
  `POST /dashboard/bookings/{booking}/pagos` (`dashboard.bookings.payments.store`).
- Checkout simulado (`routes/demo.php`, incluido desde `routes/web.php` **solo** si
  `config('payments.provider') === 'simulated'`):
  - `GET /demo/pagos/{payment}/checkout` (`demo.payments.checkout`, middleware `signed`).
  - `POST /demo/pagos/{payment}/resultado` (`demo.payments.outcome`, middleware `signed`), con
    `outcome ∈ {approved, rejected, abandoned}`.

Comportamiento de los botones:

| Botón | Estado del proveedor | Evento |
|---|---|---|
| Aprobar | `approved` | encola `DeliverSimulatedProviderWebhook` |
| Rechazar | `rejected` | encola `DeliverSimulatedProviderWebhook` |
| **Abandonar** | **sin cambios** (`pending`) | **ninguno**; redirige a Mis reservas |

Abandonar abandona de verdad: deja que el proveedor expire solo y que la reconciliación y
`bookings:expire-unpaid` demuestren el camino completo.

Seguridad de demo: cartel permanente *"ENTORNO DE DEMOSTRACIÓN — pago simulado. No ingreses datos
reales de tarjeta."*, **cero campos de tarjeta o de credenciales**, y ninguna imitación visual de una
pasarela real. La presentación fina es Fase 11; esta fase entrega comportamiento correcto y rotulado.

## 16. Autorización y tenancy

`App\Policies\PaymentPolicy` espeja la propiedad de `BookingPolicy`:

| Habilidad | Quién |
|---|---|
| `viewAny` (pagos de una reserva) | cliente dueño de la reserva, o staff (`owner`/`admin`/`employee`) del negocio de la reserva |
| `view` | ídem |
| `create` (iniciar/reintentar) | ídem |

El webhook **no** puede depender del middleware de tenancy: el proveedor no es un usuario autenticado.
Resuelve el pago por `(provider, external_id)` levantando `BusinessScope`, y **deriva** negocio y
reserva desde la fila local. Un `business_id` que viniera en el payload se ignora por completo: nunca
es autorización. El aislamiento cross-tenant tiene test propio.

## 17. Seguridad: firma, montos, payloads y logs

**Firma.** Header `X-ReservaHub-Signature: t=<unix>,v1=<hmac_sha256("<t>.<rawBody>", secret)>`,
comparado con `hash_equals`, tolerancia `PAYMENTS_WEBHOOK_TOLERANCE_SECONDS` (300 s) contra replay.
Verificación **dentro del adapter**, siempre contra el `rawBody` exacto (`$request->getContent()`),
nunca contra el array ya parseado. El secreto sale solo de config/env; jamás se loguea, se persiste ni
se devuelve.

**Monto y moneda.** La fila local es la autoridad: `payments.amount` (snapshot de
`bookings.deposit_amount`) y `payments.currency` (snapshot de `businesses.currency`). Se compara
`bccomp($payment->amount, $result->amount, 2) === 0` y moneda idéntica. Discrepancia → **ningún** cambio
de estado, evento `ignored` con `amount_mismatch` / `currency_mismatch`, log warning con ids y ambos
montos (sin payload). El monto entrante nunca se toma como verdad: repetir el mismo evento no puede
corregirlo, por eso es terminal.

**Redacción por lista blanca** antes de persistir cualquier payload: solo `event_id`, `payment_id`,
`status`, `amount`, `currency`, `occurred_at`, `reference`, `failure_reason`. Todo lo demás se
descarta.
**Los headers no se persisten** (ni la firma ni `Authorization`). El proveedor simulado nunca emite
datos de tarjeta y la UI nunca los pide, así que el repositorio no puede acumular datos de pago reales.
`webhook_events` es diagnóstico interno: sin API, sin UI, sin exposición al cliente. Sin purga
automática en esta fase (decisión explícita).

**Logs.** Firma inválida: `warning` con proveedor, IP y motivo — sin cuerpo, sin firma, sin secreto.
Aplicación no efectuada (`booking_not_pending`, mismatches, transiciones ilegales): `warning` con ids.
Errores internos: `error` con mensaje, sin payload.

## 18. Concurrencia

Orden de bloqueo global, en todos los caminos: **`webhook_events` → `bookings` → `payments`**.
La reconciliación no toca `webhook_events`, así que no hay ciclo posible.

| Carrera | Mecanismo | Test |
|---|---|---|
| Doble entrega concurrente del mismo evento | `unique(provider, external_event_id)` + `FOR UPDATE` con re-chequeo | 2 sesiones PDO: un solo `processed`, una sola confirmación, un solo email |
| Webhook vs reconciliación sobre el mismo pago | `FOR UPDATE` sobre `payments` + guard de transición | 2 sesiones: estado final único, `ConfirmBooking` una sola vez |
| Dos iniciaciones concurrentes | lock de la fila de `bookings` + `unique(booking_id) where status='pending'` | 2 sesiones: una fila `pending`; la otra devuelve el mismo intento |
| `bookings:expire-unpaid` vs `approved` en el límite | lock de `bookings` primero + re-chequeo dentro del lock | 2 sesiones: la reserva termina `confirmed` **o** `cancelled`, nunca ambos efectos |
| Dos corridas de reconciliación | `withoutOverlapping` + guard de transición | segunda corrida no cambia nada |

Ningún mecanismo de concurrencia se agrega sin un test del invariante que dice proteger.

## 19. Fallos del proveedor y mapa HTTP

| Situación | Clasificación | HTTP | Persistencia |
|---|---|---|---|
| Procesado (incluye `booking_not_pending`) | éxito | 200 | evento `processed` |
| Entrega duplicada | éxito | 200 | ninguna nueva |
| Monto/moneda distintos, estado terminal previo, transición ilegal | terminal, no reintentable | 200 | evento `ignored` + `outcome_reason` |
| Pago externo desconocido | **anómalo, reintentable** | **500** | evento `failed`, `attempts++` |
| Firma inválida o fuera de tolerancia | rechazo | 401 | **ninguna** |
| Proveedor desconocido en la ruta | rechazo | 404 | ninguna |
| Payload ilegible (sin identidad de evento) | rechazo | 422 | ninguna |
| Error interno tras el claim (DB, etc.) | reintentable | 500 | evento `failed`, `attempts++` |
| Proveedor caído durante reconciliación | reintentable | — | `last_reconcile_attempt_at` estampado, `last_reconciled_at` intacto, log warning |
| `createCheckout` falla en iniciación | reintentable por el usuario | 502 vía envelope (`No se pudo iniciar el pago.`) | ninguna (rollback) |

Ningún detalle interno del proveedor se filtra en respuestas públicas.

`GatewayUnavailableException` se mapea en `bootstrap/app.php` junto al resto: en `api/*` devuelve el
envelope con 502 y mensaje genérico (`No se pudo iniciar el pago. Probá de nuevo en unos minutos.`);
en web, redirect `back()` con error de sesión. El mensaje del proveedor nunca se propaga.

## 20. Configuración y entorno

`config/payments.php`:

```php
return [
    'provider' => env('PAYMENTS_PROVIDER', 'simulated'),
    'window_minutes' => (int) env('PAYMENTS_WINDOW_MINUTES', 30),
    'webhook_tolerance_seconds' => (int) env('PAYMENTS_WEBHOOK_TOLERANCE_SECONDS', 300),
    'reconcile' => [
        'batch' => (int) env('PAYMENTS_RECONCILE_BATCH', 100),
        'cadence_minutes' => (int) env('PAYMENTS_RECONCILE_CADENCE_MINUTES', 5),
    ],
    'simulated' => [
        'webhook_secret' => env('PAYMENTS_SIMULATED_WEBHOOK_SECRET'),
    ],
];
```

`.env.example` (placeholders de desarrollo, nunca valores reales):

```
PAYMENTS_PROVIDER=simulated
PAYMENTS_SIMULATED_WEBHOOK_SECRET=local-development-secret-change-me
PAYMENTS_WINDOW_MINUTES=30
PAYMENTS_WEBHOOK_TOLERANCE_SECONDS=300
PAYMENTS_RECONCILE_BATCH=100
PAYMENTS_RECONCILE_CADENCE_MINUTES=5
```

Clasificación: `PAYMENTS_SIMULATED_WEBHOOK_SECRET` es **secreto del operador** (no va a git con valor
real); el resto es configuración de aplicación. La caída de proveedor para tests se simula desde el
propio adapter en el test (inyección/estado del proveedor simulado), no con una variable de entorno de
producción.

Contrato de runtime para `docs/DEPLOYMENT_HANDOFF.md`:

- El scheduler existente gana `payments:reconcile` y `bookings:expire-unpaid`. **Sin contenedor,
  puerto, dominio, túnel ni servicio público nuevo.**
- La entrega simulada es **en proceso y no depende de HTTP, DNS, Cloudflare ni del hostname público**.
- Aun así, **si ReservaHub está expuesto públicamente, la ruta de webhook es alcanzable por el
  hostname normal de la aplicación**: verificación de firma, tolerancia temporal, validación de
  payload y rate limiting son obligatorios en producción.
- `/demo/*` solo se registra con proveedor `simulated`.
- Sin datos persistentes nuevos en disco: todo vive en PostgreSQL, ya respaldado.
- El reloj del host ahora también afecta a la tolerancia de firma, no solo al scheduler.

## 21. Layout de archivos

```
app/
├── Actions/
│   ├── Bookings/CancelBooking.php            (modificado: ?User + CancellationReason)
│   ├── Bookings/ConfirmBooking.php           (modificado: ?User)
│   ├── Bookings/CreateBooking.php            (modificado: payment_expires_at)
│   ├── Bookings/RescheduleBooking.php        (modificado: ajusta la ventana solo hacia abajo)
│   └── Payments/{InitiatePayment,ApplyPaymentResult}.php
├── Console/Commands/{ReconcilePayments,ExpireUnpaidBookings}.php
├── Enums/{PaymentStatus,PaymentApplicationOutcome,WebhookEventStatus,
│          WebhookProcessingStatus,CancellationReason}.php
├── Events/BookingCancelled.php               (modificado: ?User + reason)
├── Http/
│   ├── Controllers/Api/{PaymentController,PaymentWebhookController}.php
│   ├── Controllers/Dashboard/BookingPaymentController.php
│   ├── Controllers/Public/BookingPaymentController.php
│   ├── Controllers/Demo/SimulatedCheckoutController.php
│   └── Resources/PaymentResource.php
├── Jobs/DeliverSimulatedProviderWebhook.php
├── Listeners/{SendBookingConfirmedNotifications,SendBookingCancelledNotifications}.php  (afterCommit)
├── Models/{Payment,WebhookEvent}.php
├── Notifications/Bookings/BookingCancelledNotification.php  (modificado: actor sistema)
├── Policies/PaymentPolicy.php
├── Providers/AppServiceProvider.php          (modificado: binding de PaymentGateway +
│                                              rate limiter `payment-webhooks`)
└── Services/Payments/
    ├── Contracts/PaymentGateway.php
    ├── Data/{CheckoutRequest,CheckoutResult,WebhookEnvelope,WebhookNotification,
    │          ProviderSnapshot,PaymentResult,WebhookProcessingResult}.php
    ├── Exceptions/{InvalidWebhookSignature,MalformedWebhookPayload,
    │                GatewayUnavailable,UnknownProviderPayment}Exception.php
    ├── ProcessPaymentWebhook.php
    ├── WebhookPayloadRedactor.php
    └── Simulated/{SimulatedPaymentGateway,SimulatedProviderPayment}.php
config/payments.php
database/migrations/  (payments, webhook_events, simulated_provider_payments,
                       bookings.payment_expires_at)
database/factories/{PaymentFactory,WebhookEventFactory}.php
routes/demo.php
resources/js/Pages/Demo/Checkout.jsx
```

## 22. Tests

PostgreSQL real (nunca SQLite), adapter simulado, **cero red**. Concurrencia con dos sesiones PDO
reales + `DatabaseMigrations`, siguiendo `AdvisoryLockTest` y `UserStatusConcurrencyTest`.

| Archivo | Cubre |
|---|---|
| `tests/Unit/Enums/PaymentStatusTest.php` | matriz de transiciones legales; monotonía terminal; ausencia de `expired → approved` |
| `tests/Unit/Services/Payments/SimulatedPaymentGatewayTest.php` | `createCheckout`; HMAC válido/inválido/fuera de tolerancia; payload ilegible; `fetchPayment` para pending/approved/rejected/expired, desconocido y caída; ciclo monótono del proveedor (aprobar tras expiry se rechaza); `checkoutUrl` fresca y acotada a `expiresAt` |
| `tests/Unit/Services/Payments/WebhookPayloadRedactorTest.php` | lista blanca; headers, firma y secreto nunca persistidos |
| `tests/Feature/Payments/InitiatePaymentTest.php` | reserva sin seña → 422; con seña → 201; monto/moneda desde datos de la app; reserva no `pending` → 422; ventana vencida → 422 (aunque el comando no haya corrido); repetición devuelve el intento vivo (200); autorización cliente dueño / staff; cross-tenant; fallo de `createCheckout` → rollback total |
| `tests/Feature/Payments/WebhookEndpointTest.php` | firma válida → 200 `processed`; inválida → 401 sin persistencia; fuera de tolerancia → 401; proveedor desconocido → 404; payload ilegible → 422 sin persistencia; `approved` confirma exactamente una vez; `pending` no cambia nada; `rejected` no confirma; pago desconocido → 500 + evento `failed`; monto/moneda distintos → 200 `ignored`; estado terminal previo → `ignored`; reserva cancelada → `processed` + `booking_not_pending` sin resurrección; `Notification::fake()` → un solo email |
| `tests/Feature/Payments/WebhookIdempotencyTest.php` | entrega duplicada secuencial: un `processed`, un efecto; evento `failed` **sí** se reprocesa en el reintento; evento `processed` nunca re-ejecuta; `attempts` se incrementa |
| `tests/Feature/Payments/WebhookConcurrencyTest.php` | 2 sesiones PDO: duplicado concurrente → un efecto; webhook vs reconciliación → una sola confirmación; dos iniciaciones concurrentes → un `pending`; `expire-unpaid` vs `approved` en el límite → `confirmed` XOR `cancelled` |
| `tests/Feature/Payments/ReconciliationTest.php` | pendiente elegible consultado; `approved`/`rejected`/`expired` aplicados vía `ApplyPaymentResult`; proveedor caído → `last_reconcile_attempt_at` estampado, `last_reconciled_at` intacto, warning, reintento en la corrida siguiente; terminal salteado; rerun idempotente; lote acotado y orden `NULLS FIRST`; pago antiguo aún pendiente sigue elegible; **anti-inanición: con más pendientes elegibles que el tamaño de lote y los primeros fallando, los posteriores se inspeccionan en corridas siguientes** |
| `tests/Feature/Payments/ExpireUnpaidBookingsTest.php` | sin intentos → cancela; todos terminales no aprobados → cancela; intento `pending` → **no** cancela; `approved` tardío vía reconciliación → confirma y no cancela; reserva cancelada o confirmada a mano → intacta; historial con `changed_by = null` y motivo; un solo email; **cancela aunque la reserva ya esté dentro del corte de `cancellation_hours` del cliente**; un cliente en esa misma situación sigue sin poder cancelar por su cuenta (comportamiento actual intacto); `Requested` sin actor y `PaymentWindowExpired` con actor → `InvalidArgumentException` |
| `tests/Feature/Payments/PaymentsApiTest.php` | envelope; listado y detalle; `checkout_url` solo con intento vivo y actor autorizado; aislamiento cross-tenant; `last_snapshot` jamás expuesto; paginación/orden si aplica |
| `tests/Feature/Payments/SimulatedCheckoutTest.php` | URL sin firma → rechazada; Aprobar/Rechazar mutan al proveedor y encolan la entrega; **Abandonar no muta ni encola**; la página no tiene campos de tarjeta y muestra el cartel; rutas ausentes con otro proveedor |
| `tests/Feature/Payments/DeliverSimulatedProviderWebhookTest.php` | `t` y HMAC generados en `handle()` (un retraso de cola mayor que la tolerancia sigue siendo válido); reintento conserva `external_event_id`; `tries`/`backoff`/`afterCommit` |
| `tests/Feature/Payments/PaymentWindowTest.php` | `CreateBooking` fija la ventana solo con seña y con el clamp a `starts_at`; sin seña queda `null`; `RescheduleBooking` la ajusta hacia abajo con un turno anterior y **no la extiende** con uno posterior; una reserva `pending` con seña y ventana `null` no puede iniciar pago y no es cancelada automáticamente; la migración rellena las reservas `pending` con seña preexistentes con una ventana fresca (no vencida) |
| `tests/Feature/Tenancy/PaymentsSchemaTest.php` | columnas; `unique(provider, external_id)`; `unique(booking_id) where status='pending'`; `unique(provider, external_event_id)`; FKs; scope por negocio en `Payment`; `WebhookEvent` sin scope |
| Suite existente | los 358 tests actuales siguen verdes; una reserva sin seña conserva su comportamiento exacto |

## 23. Documentación a actualizar

| Archivo | Cambio |
|---|---|
| `01-reservahub.md` | tabla de estado (Fase 9, con evidencia); §2 pagos; §5 endpoints (anidados + webhook, con el motivo del desvío); reglas de negocio (+ventana de pago); detalle de la Fase 9; terminología `simulated` (no `fake`) en todo el roadmap |
| `docs/api.md` | endpoints de pagos, códigos de error, ejemplo completo; el webhook documentado como ruta de proveedor |
| OpenAPI (Scramble) | se infiere de Resources/Requests como el resto; anotaciones a mano solo donde el tipo no se infiera |
| `.env.example` | seis variables nuevas con placeholders |
| `docs/DEPLOYMENT_HANDOFF.md` | §4 entorno (secreto del operador); §7 smoke (`schedule:list` muestra los dos comandos nuevos); §9 `/demo/*` solo con proveedor simulado; §10 reloj y tolerancia de firma; aclaración de exposición del webhook (§20) |
| `CLAUDE.md` | sección durable "Pagos (Fase 9)": `ApplyPaymentResult` único camino de aplicación, `ProcessPaymentWebhook` único borde, orden de locks `webhook_events → bookings → payments`, expiración propiedad de la reserva, proveedor simulado con estado independiente, `CancellationReason` |

## 24. Riesgos y decisiones asumidas

1. **La transacción de iniciación abarca al adapter.** Válido porque el proveedor simulado escribe en
   la misma base. Un adapter real exigiría referencia/idempotency-key generada por la app y una fase de
   confirmación: documentado como límite conocido, fuera de alcance.
2. **Proveedor caído = slot bloqueado.** `expire-unpaid` no cancela mientras haya un intento `pending`.
   Se prefiere un turno bloqueado a cancelar una reserva quizá pagada.
3. **Sin reembolsos.** Un pago aprobado sobre una reserva no `pending` queda registrado como
   `booking_not_pending` y se resuelve fuera de la aplicación.
4. **Sin purga de `webhook_events`.** El volumen de la demo no lo justifica; queda anotado para una
   fase futura.
5. **La cancelación automática omite el corte de `cancellation_hours`** de forma explícita y probada,
   nunca como efecto colateral de un actor nulo.
6. **`payments.amount` y `payments.currency` son snapshots.** Cambiar el precio del servicio o la
   moneda del negocio no reescribe intentos existentes.
