# Fase 6 — Notificaciones y scheduler

Diseño validado el 2026-08-11. Implementa el punto "Fase 6 — Notificaciones y scheduler" de `01-reservahub.md`.

## Objetivo

Notificar a clientes y empleados sobre el ciclo de vida de una reserva (creación, confirmación, reprogramación, cancelación) y enviar recordatorios 24 h y 2 h antes del turno, por email y por base de datos, con los envíos encolados y los recordatorios protegidos contra duplicados.

## Estado de partida

Fases 0–5 terminadas: auth e invitaciones, tenancy por `business_id` con `BusinessScope`, servicios y empleados, `AvailabilityService`, seis Actions de reserva con transacción y `pg_advisory_xact_lock`, historial de estados, dashboard y flujo público.

Lo que falta y esta fase agrega:

- No existe la tabla `notifications`, así que el canal `database` no funciona.
- Solo existe el evento `BookingCreated` y no tiene listeners; cancelar, reprogramar, confirmar, completar y marcar ausencia no disparan nada.
- No hay comando de recordatorios, ni scheduler (`routes/console.php` solo define `inspire`), ni mecanismo de deduplicación.
- `QUEUE_CONNECTION=database` y la tabla `jobs` existe, pero no hay ningún worker corriendo: `compose.yaml` define `laravel.test`, `pgsql`, `redis`, `mailpit` y nada más.
- La única notificación existente, `EmployeeInvited`, se envía de forma síncrona con un comentario que anticipa esta fase.

## Alcance

Entra:

- Notificaciones de creación, confirmación, reprogramación y cancelación de reserva.
- Recordatorios de 24 h y 2 h.
- Canales email y base de datos.
- Encolado de los envíos y worker de colas en Docker.
- Comando de recordatorios, scheduler y deduplicación.

No entra:

- Interfaz para leer las notificaciones persistidas (campanita, bandeja). Se persisten y se muestran en una fase posterior.
- Canal de WhatsApp. `01-reservahub.md` lo marca opcional.
- Notificaciones al pasar una reserva a `completed` o `no_show`.
- Preferencias de notificación por usuario.

## Modelo de datos

Dos migraciones.

`notifications`: la tabla estándar de Laravel (`php artisan make:notifications-table`), sin modificaciones.

`booking_reminders`:

```text
id
booking_id   FK bookings, cascadeOnDelete
type         string: '24h' | '2h'
sent_at      timestamp
created_at
updated_at
unique (booking_id, type)
```

No lleva `business_id`: es hijo de `bookings`, igual que `booking_status_histories`, y siempre se consulta a través de la reserva.

El índice único es el mecanismo de idempotencia. El comando inserta la fila con `insertOrIgnore` **antes** de notificar; si la inserción afecta cero filas, otro proceso ya reclamó ese recordatorio y este lo saltea. Eso protege incluso si dos instancias del comando corren en paralelo, cosa que `withoutOverlapping()` por sí solo no garantiza entre hosts.

Modelo `App\Models\BookingReminder` con `booking()` y un enum `App\Enums\ReminderType` (`TwentyFourHours = '24h'`, `TwoHours = '2h'`) que expone el offset en horas.

## Eventos y listeners

Cuatro eventos planos en `app/Events/`, con `Dispatchable`, sin broadcasting todavía. Fase 9 los reutiliza agregándoles `ShouldBroadcast`.

| Evento | Se dispara en | Payload |
|---|---|---|
| `BookingCreated` (ya existe) | `CreateBooking`, después del commit | `Booking` |
| `BookingConfirmed` | `ConfirmBooking` | `Booking` |
| `BookingRescheduled` | `RescheduleBooking`, después del commit | `Booking`, `CarbonImmutable $previousStartsAt` |
| `BookingCancelled` | `CancelBooking` | `Booking`, `User $cancelledBy` |

`RescheduleBooking` hoy devuelve desde dentro de la transacción y arma `$oldStart` como string para el historial. Hay que reestructurarlo para que la transacción devuelva la reserva y el evento se dispare después del commit, con el `starts_at` previo como `CarbonImmutable`.

Un listener por evento en `app/Listeners/`, cada uno `implements ShouldQueue`, descubiertos automáticamente por el type-hint de `handle()`:

- `SendBookingCreatedNotifications`
- `SendBookingConfirmedNotifications`
- `SendBookingRescheduledNotifications`
- `SendBookingCancelledNotifications`

Cada listener resuelve destinatarios y despacha las notificaciones. Los listeners no formatean texto; eso vive en las notificaciones.

## Notificaciones

Enum `App\Enums\NotificationAudience` con `Customer` y `Employee`, para no duplicar una clase por destinatario.

Clase base abstracta `App\Notifications\Bookings\BookingNotification`, `implements ShouldQueue`, `via()` devuelve `['mail', 'database']`. Aporta:

- `formatDateTime(Booking $booking): string` — convierte a `business->timezone` y formatea como `mié 12 ago 2026, 14:30` con el locale `es`.
- `actionUrl(NotificationAudience $audience): string` — `/mis-reservas` para el cliente, el listado de reservas del dashboard para el empleado. Ambas rutas requieren autenticación y todos los clientes tienen cuenta: `routes/public.php` protege `booking.create` y `booking.store` con `auth`.
- La forma común del payload de `toArray()`: `booking_id`, `type`, `starts_at`, `service`, y el nombre de la contraparte.

Cinco notificaciones concretas:

| Clase | Constructor | Destinatarios |
|---|---|---|
| `BookingCreatedNotification` | `Booking`, `NotificationAudience` | cliente y empleado |
| `BookingConfirmedNotification` | `Booking` | cliente |
| `BookingRescheduledNotification` | `Booking`, `CarbonImmutable $previousStartsAt`, `NotificationAudience` | cliente y empleado |
| `BookingCancelledNotification` | `Booking`, `User $cancelledBy`, `NotificationAudience` | cliente y empleado |
| `BookingReminderNotification` | `Booking`, `ReminderType` | cliente |

Reglas de contenido:

- `BookingCreatedNotification` cambia el texto según el estado con el que nació la reserva. `CreateBooking` la deja en `pending` cuando el servicio tiene `deposit_amount > 0`; en ese caso el mail avisa que queda pendiente de pago. Con `confirmed`, confirma directamente.
- `BookingCancelledNotification` cambia el texto según quién canceló: comparar `$cancelledBy->id` contra `booking->customer_id` distingue "cancelaste tu turno" / "el cliente canceló" de una cancelación hecha por el negocio.
- Todo en español, fechas en la zona horaria del negocio.
- `MailMessage` estándar, sin plantillas publicadas, siguiendo el estilo de `EmployeeInvited`.

`EmployeeInvited` pasa a `implements ShouldQueue` y se le borra el comentario que decía que se revisara al conectar las colas.

## Comando de recordatorios

`App\Console\Commands\SendBookingReminders`, firma `bookings:send-reminders`.

Para cada `ReminderType`:

1. Buscar reservas con `status = confirmed`, `starts_at > now()`, `starts_at <= now() + offset`, y sin fila en `booking_reminders` para ese tipo.
2. Para `TwentyFourHours` exigir además `starts_at > now() + 2 horas`. Sin esa guarda, una reserva creada con poca antelación dispararía el recordatorio de 24 h y el de 2 h casi al mismo tiempo.
3. Por cada reserva: `insertOrIgnore` en `booking_reminders`; si afectó una fila, enviar `BookingReminderNotification` al cliente.

El límite superior es `starts_at <= now() + offset` en vez de un rango cerrado, así que si el scheduler estuvo caído los recordatorios atrasados salen igual en la corrida siguiente en lugar de perderse.

El comando corre en consola, donde `BusinessScope` no aplica filtro (`apply()` deja pasar la query cuando `Business::current()` es null y `app()->runningInConsole()`). Eso es lo correcto para un comando de plataforma: recorre las reservas de todos los negocios en una sola pasada.

En `routes/console.php`:

```php
Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping();
```

## Infraestructura

`compose.yaml` suma dos servicios que reutilizan la imagen `sail-8.5/app` ya construida, con el mismo volumen y la misma red que `laravel.test`, y `depends_on` sobre `pgsql` y `redis`:

- `queue`: `php artisan queue:work --tries=3`
- `scheduler`: `php artisan schedule:work`

`.env` y `.env.example` pasan a `QUEUE_CONNECTION=redis`. El contenedor `redis` ya está levantado sin uso y `REDIS_CLIENT=phpredis` ya está configurado. La tabla `jobs` queda, inofensiva.

`phpunit.xml` ya fija `QUEUE_CONNECTION=sync` y `MAIL_MAILER=array`, así que los tests no necesitan worker.

Actualizar `CLAUDE.md` con los servicios nuevos y cómo mirar los mails en Mailpit.

## Manejo de errores

- Un fallo al enviar un mail no debe tirar abajo la petición HTTP: por eso los listeners son `ShouldQueue`. Con `QUEUE_CONNECTION=redis` el fallo queda en la cola y se reintenta hasta tres veces.
- Si `BookingReminderNotification` falla después de que la fila de `booking_reminders` ya se insertó, ese recordatorio se pierde. Es la contrapartida aceptada de insertar antes de enviar; el orden inverso arriesgaría mandar el mail dos veces, que es peor. Los reintentos de la cola cubren los fallos transitorios del worker.
- Un usuario borrado deja las FK de la reserva en cascada, así que el listener nunca ve un destinatario nulo.

## Tests

Sobre el comando. Tocan la base, así que van en `tests/Feature/`:

- Toma una reserva confirmada que empieza dentro de las 24 h y manda el recordatorio de 24 h.
- No manda el de 24 h cuando el turno está a menos de 2 h; manda el de 2 h.
- Ignora reservas `pending`, `cancelled`, `completed` y `no_show`.
- Ignora reservas ya pasadas.
- Catch-up: una reserva que quedó atrasada respecto de su ventana igual recibe el recordatorio.

Sobre los eventos y las notificaciones, con `Notification::fake()`:

- Un test por evento verificando la notificación correcta a cada destinatario.
- `BookingCreatedNotification` con seña avisa pendiente de pago; sin seña, confirma.
- `BookingCancelledNotification` cambia el texto según quién canceló.
- Deduplicación: correr el comando dos veces produce una sola notificación por tipo.
- Dos negocios distintos: ambas reservas reciben recordatorio en la misma corrida.
- Con `Event::fake()` y `Queue::fake()`: las Actions disparan los eventos y los listeners se encolan en vez de correr en línea.

## Archivos

Nuevos:

```text
app/Console/Commands/SendBookingReminders.php
app/Enums/NotificationAudience.php
app/Enums/ReminderType.php
app/Events/BookingCancelled.php
app/Events/BookingConfirmed.php
app/Events/BookingRescheduled.php
app/Listeners/SendBookingCancelledNotifications.php
app/Listeners/SendBookingConfirmedNotifications.php
app/Listeners/SendBookingCreatedNotifications.php
app/Listeners/SendBookingRescheduledNotifications.php
app/Models/BookingReminder.php
app/Notifications/Bookings/BookingCancelledNotification.php
app/Notifications/Bookings/BookingConfirmedNotification.php
app/Notifications/Bookings/BookingCreatedNotification.php
app/Notifications/Bookings/BookingNotification.php
app/Notifications/Bookings/BookingRescheduledNotification.php
app/Notifications/Bookings/BookingReminderNotification.php
database/factories/BookingReminderFactory.php
database/migrations/..._create_notifications_table.php
database/migrations/..._create_booking_reminders_table.php
tests/Feature/Notifications/BookingNotificationsTest.php
tests/Feature/Notifications/BookingRemindersCommandTest.php
```

Modificados:

```text
.env.example
CLAUDE.md
app/Actions/Bookings/CancelBooking.php
app/Actions/Bookings/ConfirmBooking.php
app/Actions/Bookings/RescheduleBooking.php
app/Models/Booking.php            relación reminders()
app/Notifications/EmployeeInvited.php
compose.yaml
routes/console.php
```
