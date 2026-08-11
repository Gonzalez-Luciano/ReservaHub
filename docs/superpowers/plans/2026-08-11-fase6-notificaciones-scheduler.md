# Fase 6 — Notificaciones y scheduler: plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Notificar a clientes y empleados el ciclo de vida de una reserva por email y base de datos, con envíos encolados y recordatorios de 24 h y 2 h protegidos contra duplicados.

**Architecture:** Cada Action de reserva dispara un evento plano después del commit. Un listener `ShouldQueue` por evento resuelve destinatarios y despacha notificaciones que extienden una base común (`BookingNotification`). Los recordatorios los emite un comando de consola agendado cada cinco minutos, que reclama cada envío insertando una fila en `booking_reminders` con índice único `(booking_id, type)` antes de notificar.

**Tech Stack:** Laravel 12 sobre PHP 8.5, PostgreSQL 18, Redis (colas), Mailpit (SMTP de desarrollo), PHPUnit, Docker vía Laravel Sail.

**Spec:** `docs/superpowers/specs/2026-08-11-fase6-notificaciones-scheduler-design.md`

## Global Constraints

- Todo el texto de cara al usuario va en español. El locale de la app es `es`.
- Las fechas se muestran siempre en `business->timezone`, formato `mié 12 ago 2026, 14:30`.
- Todas las notificaciones son `ShouldQueue` y usan `via() => ['mail', 'database']`.
- Todos los listeners son `ShouldQueue`.
- Nunca usar `Event::fake()` sin argumentos en un test: reemplaza el dispatcher de Eloquent y anula el hook `creating` de `BelongsToBusiness`, dejando los modelos sin `business_id`. Usar `Notification::fake()` o `Event::fake([ClaseConcreta::class])`.
- Las notificaciones nunca acceden a `$booking->service` de forma directa: `Service` usa `BelongsToBusiness` y en una petición web sin negocio ligado el scope global tira `MissingBusinessContextException`. Usar siempre el helper `service()` de la clase base.
- Los tests son clases PHPUnit en `Tests\Feature\*` / `Tests\Unit\*` con `use RefreshDatabase;`, no Pest.
- Comandos siempre dentro del contenedor:
  ```bash
  docker compose exec laravel.test php artisan test --filter=NombreDelTest
  docker compose exec laravel.test vendor/bin/pint --test
  ```
- No entra en esta fase: interfaz de bandeja de notificaciones, canal de WhatsApp, notificaciones de `completed` / `no_show`, preferencias por usuario.

## Estructura de archivos

**Crear:**

| Archivo | Responsabilidad |
|---|---|
| `app/Enums/ReminderType.php` | Tipos de recordatorio y su offset en horas |
| `app/Enums/NotificationAudience.php` | Distingue destinatario cliente / empleado |
| `app/Models/BookingReminder.php` | Registro de recordatorio ya emitido |
| `database/factories/BookingReminderFactory.php` | Factory del anterior |
| `database/migrations/..._create_notifications_table.php` | Canal `database` |
| `database/migrations/..._create_booking_reminders_table.php` | Dedupe de recordatorios |
| `app/Notifications/Bookings/BookingNotification.php` | Base: formateo de fecha, URL de acción, payload común |
| `app/Notifications/Bookings/BookingCreatedNotification.php` | Reserva creada |
| `app/Notifications/Bookings/BookingConfirmedNotification.php` | Reserva confirmada |
| `app/Notifications/Bookings/BookingRescheduledNotification.php` | Reserva reprogramada |
| `app/Notifications/Bookings/BookingCancelledNotification.php` | Reserva cancelada |
| `app/Notifications/Bookings/BookingReminderNotification.php` | Recordatorio 24 h / 2 h |
| `app/Events/BookingConfirmed.php` | Evento |
| `app/Events/BookingRescheduled.php` | Evento, lleva el `starts_at` anterior |
| `app/Events/BookingCancelled.php` | Evento, lleva quién canceló |
| `app/Listeners/SendBookingCreatedNotifications.php` | Despacha a cliente y empleado |
| `app/Listeners/SendBookingConfirmedNotifications.php` | Despacha al cliente |
| `app/Listeners/SendBookingRescheduledNotifications.php` | Despacha a cliente y empleado |
| `app/Listeners/SendBookingCancelledNotifications.php` | Despacha a cliente y empleado |
| `app/Console/Commands/SendBookingReminders.php` | Selección de reservas y reclamo idempotente |

**Modificar:** `app/Models/Booking.php` (relación `reminders()`), `app/Actions/Bookings/ConfirmBooking.php`, `app/Actions/Bookings/CancelBooking.php`, `app/Actions/Bookings/RescheduleBooking.php`, `app/Notifications/EmployeeInvited.php`, `routes/console.php`, `compose.yaml`, `.env.example`, `CLAUDE.md`.

---

### Task 1: Esquema de recordatorios

**Files:**
- Create: `app/Enums/ReminderType.php`
- Create: `app/Models/BookingReminder.php`
- Create: `database/factories/BookingReminderFactory.php`
- Create: `database/migrations/2026_08_11_000001_create_notifications_table.php`
- Create: `database/migrations/2026_08_11_000002_create_booking_reminders_table.php`
- Modify: `app/Models/Booking.php`
- Test: `tests/Feature/Tenancy/BookingRemindersSchemaTest.php`

**Interfaces:**
- Produces: `App\Enums\ReminderType` con casos `TwentyFourHours = '24h'` y `TwoHours = '2h'`, método `hoursBefore(): int`. `App\Models\BookingReminder` con `booking(): BelongsTo`, casts `type => ReminderType`, `sent_at => datetime`. `Booking::reminders(): HasMany`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Tenancy/BookingRemindersSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\BookingReminder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingRemindersSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('notifications'));
    }

    public function test_booking_reminders_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('booking_reminders', [
            'id', 'booking_id', 'type', 'sent_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_a_reminder_belongs_to_its_booking(): void
    {
        $booking = Booking::factory()->create();
        $reminder = BookingReminder::factory()->for($booking)->create([
            'type' => ReminderType::TwentyFourHours,
        ]);

        $this->assertTrue($reminder->booking->is($booking));
        $this->assertSame(ReminderType::TwentyFourHours, $reminder->type);
        $this->assertTrue($booking->reminders()->whereKey($reminder->id)->exists());
    }

    public function test_the_same_reminder_type_cannot_be_stored_twice_for_one_booking(): void
    {
        $booking = Booking::factory()->create();
        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);

        $this->expectException(QueryException::class);

        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);
    }

    public function test_both_reminder_types_can_coexist_for_one_booking(): void
    {
        $booking = Booking::factory()->create();

        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwentyFourHours]);
        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);

        $this->assertSame(2, $booking->reminders()->count());
    }

    public function test_deleting_the_booking_deletes_its_reminders(): void
    {
        $booking = Booking::factory()->create();
        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);

        $booking->delete();

        $this->assertSame(0, BookingReminder::count());
    }

    public function test_hours_before_maps_each_type(): void
    {
        $this->assertSame(24, ReminderType::TwentyFourHours->hoursBefore());
        $this->assertSame(2, ReminderType::TwoHours->hoursBefore());
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BookingRemindersSchemaTest`
Expected: FAIL con `Class "App\Enums\ReminderType" not found`.

- [ ] **Step 3: Crear el enum**

`app/Enums/ReminderType.php`:

```php
<?php

namespace App\Enums;

enum ReminderType: string
{
    case TwentyFourHours = '24h';
    case TwoHours = '2h';

    public function hoursBefore(): int
    {
        return match ($this) {
            self::TwentyFourHours => 24,
            self::TwoHours => 2,
        };
    }
}
```

- [ ] **Step 4: Crear las migraciones**

`database/migrations/2026_08_11_000001_create_notifications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

`database/migrations/2026_08_11_000002_create_booking_reminders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reminders');
    }
};
```

El unique sobre `(booking_id, type)` ya sirve de índice para las consultas por `booking_id`, así que no hace falta uno aparte.

- [ ] **Step 5: Crear el modelo y su factory**

`app/Models/BookingReminder.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReminderType;
use Database\Factories\BookingReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'type', 'sent_at'])]
class BookingReminder extends Model
{
    /** @use HasFactory<BookingReminderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'sent_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
```

`database/factories/BookingReminderFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\BookingReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingReminder>
 */
class BookingReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'type' => ReminderType::TwentyFourHours,
            'sent_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Agregar la relación en `Booking`**

En `app/Models/Booking.php`, junto a `statusHistories()`:

```php
    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class);
    }
```

- [ ] **Step 7: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=BookingRemindersSchemaTest`
Expected: PASS, 6 tests.

- [ ] **Step 8: Correr la suite completa y el formateador**

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS. Las migraciones nuevas no cambian el comportamiento existente.

Run: `docker compose exec laravel.test vendor/bin/pint --test`
Expected: sin problemas de estilo.

- [ ] **Step 9: Commit**

```bash
git add app/Enums/ReminderType.php app/Models/BookingReminder.php app/Models/Booking.php database/factories/BookingReminderFactory.php database/migrations tests/Feature/Tenancy/BookingRemindersSchemaTest.php
git commit -m "feat: add notifications and booking_reminders tables"
```

---

### Task 2: Base de las notificaciones

Sin cablear todavía a ningún evento. Esta tarea existe sola porque resuelve la trampa del scope global, que es la parte con más riesgo de toda la fase.

**Files:**
- Create: `app/Enums/NotificationAudience.php`
- Create: `app/Notifications/Bookings/BookingNotification.php`
- Test: `tests/Unit/Notifications/BookingNotificationTest.php`

**Interfaces:**
- Consumes: nada de tareas anteriores.
- Produces: `App\Enums\NotificationAudience` con casos `Customer` y `Employee`. `App\Notifications\Bookings\BookingNotification`, abstracta, `implements ShouldQueue`, constructor `__construct(public readonly Booking $booking)`, `via(): ['mail','database']`, y los métodos protegidos `service(): Service`, `formatDateTime(?CarbonInterface $moment = null): string`, `actionUrl(NotificationAudience $audience): string`, `basePayload(): array`.

**Por qué `service()` existe:** `Service` usa `BelongsToBusiness`. En `MyBookingsController::cancel` la reserva se carga con `withoutGlobalScope(BusinessScope::class)` y **no** se liga ningún `Business` al contenedor, así que un `$booking->service` dentro de una notificación tiraría `MissingBusinessContextException` en producción. Bajo PHPUnit el problema queda oculto porque `app()->runningInConsole()` es `true` y `BusinessScope::apply()` no filtra. El test de abajo lo reproduce ligando un negocio *distinto* al de la reserva, con lo cual el scope sí filtra y el bug se manifiesta.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/Notifications/BookingNotificationTest.php`:

```php
<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class BookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(string $timezone = 'America/Argentina/Buenos_Aires'): Booking
    {
        $business = Business::factory()->create(['timezone' => $timezone]);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create(['name' => 'Ana'])->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id, 'name' => 'Beto'])->id,
            'starts_at' => '2026-08-12 17:30:00',
        ]);
    }

    public function test_it_is_queued_and_uses_mail_and_database(): void
    {
        $notification = new BookingNotificationStub($this->makeBooking());

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['mail', 'database'], $notification->via(new User));
    }

    public function test_it_formats_the_start_time_in_the_business_timezone_in_spanish(): void
    {
        $notification = new BookingNotificationStub($this->makeBooking());

        // 2026-08-12 17:30 UTC es 14:30 en Buenos Aires, y ese día es miércoles.
        $this->assertSame('mié 12 ago 2026, 14:30', $notification->exposedFormatDateTime());
    }

    public function test_it_resolves_the_service_even_when_another_business_is_bound(): void
    {
        $booking = $this->makeBooking();
        app()->instance(Business::class, Business::factory()->create());

        $notification = new BookingNotificationStub($booking);

        $this->assertSame('Corte de pelo', $notification->exposedService()->name);
    }

    public function test_the_action_url_depends_on_the_audience(): void
    {
        $booking = $this->makeBooking();
        $notification = new BookingNotificationStub($booking);

        $this->assertSame(
            route('public.bookings.mine.index'),
            $notification->exposedActionUrl(NotificationAudience::Customer),
        );
        $this->assertSame(
            route('dashboard.bookings.show', $booking),
            $notification->exposedActionUrl(NotificationAudience::Employee),
        );
    }

    public function test_the_base_payload_carries_the_booking_context(): void
    {
        $booking = $this->makeBooking();

        $payload = (new BookingNotificationStub($booking))->exposedBasePayload();

        $this->assertSame($booking->id, $payload['booking_id']);
        $this->assertSame($booking->business_id, $payload['business_id']);
        $this->assertSame('Corte de pelo', $payload['service']);
        $this->assertSame('Ana', $payload['customer']);
        $this->assertSame('Beto', $payload['employee']);
    }
}

class BookingNotificationStub extends BookingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return $this->basePayload();
    }

    public function exposedFormatDateTime(): string
    {
        return $this->formatDateTime();
    }

    public function exposedService(): \App\Models\Service
    {
        return $this->service();
    }

    public function exposedActionUrl(NotificationAudience $audience): string
    {
        return $this->actionUrl($audience);
    }

    /** @return array<string, mixed> */
    public function exposedBasePayload(): array
    {
        return $this->basePayload();
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BookingNotificationTest`
Expected: FAIL con `Class "App\Enums\NotificationAudience" not found`.

- [ ] **Step 3: Crear el enum de audiencia**

`app/Enums/NotificationAudience.php`:

```php
<?php

namespace App\Enums;

enum NotificationAudience
{
    case Customer;
    case Employee;
}
```

- [ ] **Step 4: Crear la clase base**

`app/Notifications/Bookings/BookingNotification.php`:

```php
<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * El servicio se resuelve sin el scope de negocio: hay caminos web, como cancelar
     * desde "mis reservas", que no ligan ningún Business al contenedor.
     */
    protected function service(): Service
    {
        return $this->booking->service()
            ->withoutGlobalScope(BusinessScope::class)
            ->firstOrFail();
    }

    protected function formatDateTime(?CarbonInterface $moment = null): string
    {
        return ($moment ?? $this->booking->starts_at)
            ->copy()
            ->setTimezone($this->booking->business->timezone)
            ->locale('es')
            ->isoFormat('ddd D MMM YYYY, HH:mm');
    }

    protected function actionUrl(NotificationAudience $audience): string
    {
        return $audience === NotificationAudience::Customer
            ? route('public.bookings.mine.index')
            : route('dashboard.bookings.show', $this->booking);
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePayload(): array
    {
        return [
            'booking_id' => $this->booking->id,
            'business_id' => $this->booking->business_id,
            'starts_at' => $this->booking->starts_at->toIso8601String(),
            'service' => $this->service()->name,
            'customer' => $this->booking->customer->name,
            'employee' => $this->booking->employee->name,
        ];
    }
}
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=BookingNotificationTest`
Expected: PASS, 5 tests.

Si `test_it_formats_the_start_time_in_the_business_timezone_in_spanish` falla por el nombre del mes o del día, verificar que `config/app.php` resuelve `locale` en `es` y que `lang/es` está publicado; `isoFormat` toma el idioma del `->locale('es')` explícito, así que no debería depender de la config, pero el separador o la abreviatura pueden variar según la versión de Carbon. Ajustar la cadena esperada al valor real que produce Carbon, no al revés.

- [ ] **Step 6: Formatear y commitear**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

```bash
git add app/Enums/NotificationAudience.php app/Notifications/Bookings/BookingNotification.php tests/Unit/Notifications/BookingNotificationTest.php
git commit -m "feat: add base booking notification with business-scope-safe service lookup"
```

---

### Task 3: Notificación de reserva creada

Primer listener real. A partir de acá los tests que ya existen ejecutan listeners en línea, porque `phpunit.xml` fija `QUEUE_CONNECTION=sync`. Por eso el Step 6 corre la suite entera: si algo se rompe, se rompe acá y no diez tareas después.

**Files:**
- Create: `app/Notifications/Bookings/BookingCreatedNotification.php`
- Create: `app/Listeners/SendBookingCreatedNotifications.php`
- Test: `tests/Feature/Notifications/BookingCreatedNotificationTest.php`

**Interfaces:**
- Consumes: `BookingNotification`, `NotificationAudience` (Task 2). El evento `App\Events\BookingCreated` ya existe con la propiedad pública `readonly Booking $booking`.
- Produces: `BookingCreatedNotification::__construct(Booking $booking, NotificationAudience $audience)` con la propiedad pública `readonly NotificationAudience $audience`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Notifications/BookingCreatedNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\NotificationAudience;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingCreatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => $status,
        ]);
    }

    public function test_it_notifies_the_customer_and_the_employee(): void
    {
        Notification::fake();
        $booking = $this->makeBooking();

        event(new BookingCreated($booking));

        Notification::assertSentTo(
            $booking->customer,
            BookingCreatedNotification::class,
            fn (BookingCreatedNotification $notification) => $notification->audience === NotificationAudience::Customer
                && $notification->booking->is($booking),
        );
        Notification::assertSentTo(
            $booking->employee,
            BookingCreatedNotification::class,
            fn (BookingCreatedNotification $notification) => $notification->audience === NotificationAudience::Employee,
        );
    }

    public function test_the_customer_mail_confirms_when_the_booking_needs_no_deposit(): void
    {
        $booking = $this->makeBooking(BookingStatus::Confirmed);

        $mail = (new BookingCreatedNotification($booking, NotificationAudience::Customer))
            ->toMail($booking->customer);

        $this->assertStringContainsString('confirmada', $mail->subject);
        $this->assertStringNotContainsString('seña', implode(' ', $mail->introLines));
    }

    public function test_the_customer_mail_asks_for_the_deposit_when_the_booking_is_pending(): void
    {
        $booking = $this->makeBooking(BookingStatus::Pending);
        $booking->update(['deposit_amount' => 1500]);

        $mail = (new BookingCreatedNotification($booking->fresh(), NotificationAudience::Customer))
            ->toMail($booking->customer);

        $this->assertStringContainsString('pendiente', $mail->subject);
        $this->assertStringContainsString('seña', implode(' ', $mail->introLines));
    }

    public function test_the_database_payload_records_the_status(): void
    {
        $booking = $this->makeBooking(BookingStatus::Confirmed);

        $payload = (new BookingCreatedNotification($booking, NotificationAudience::Employee))
            ->toArray($booking->employee);

        $this->assertSame($booking->id, $payload['booking_id']);
        $this->assertSame('booking.created', $payload['type']);
        $this->assertSame('confirmed', $payload['status']);
    }

    public function test_the_notification_is_persisted_on_the_database_channel(): void
    {
        $booking = $this->makeBooking();

        event(new BookingCreated($booking));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $booking->customer_id,
            'notifiable_type' => User::class,
            'type' => BookingCreatedNotification::class,
        ]);
    }

    public function test_the_listener_is_queued_instead_of_running_inline(): void
    {
        Queue::fake();
        $booking = $this->makeBooking();

        event(new BookingCreated($booking));

        Queue::assertPushed(
            CallQueuedListener::class,
            fn (CallQueuedListener $job) => $job->class === SendBookingCreatedNotifications::class,
        );
        $this->assertDatabaseCount('notifications', 0);
    }
}
```

`Queue::fake()` intercepta el job antes de que se ejecute, así que este test demuestra que el listener sale de la petición en vez de correr en línea. Los imports que suma son:

```php
use App\Listeners\SendBookingCreatedNotifications;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BookingCreatedNotificationTest`
Expected: FAIL con `Class "App\Notifications\Bookings\BookingCreatedNotification" not found`.

- [ ] **Step 3: Crear la notificación**

`app/Notifications/Bookings/BookingCreatedNotification.php`:

```php
<?php

namespace App\Notifications\Bookings;

use App\Enums\BookingStatus;
use App\Enums\NotificationAudience;
use App\Models\Booking;
use Illuminate\Notifications\Messages\MailMessage;

class BookingCreatedNotification extends BookingNotification
{
    public function __construct(Booking $booking, public readonly NotificationAudience $audience)
    {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->audience === NotificationAudience::Customer
            ? $this->customerMail()
            : $this->employeeMail();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.created',
            'status' => $this->booking->status->value,
        ];
    }

    private function customerMail(): MailMessage
    {
        $when = $this->formatDateTime();
        $service = $this->service()->name;
        $business = $this->booking->business->name;

        if ($this->booking->status === BookingStatus::Pending) {
            return (new MailMessage)
                ->subject("Tu reserva en {$business} quedó pendiente de pago")
                ->greeting("Hola {$this->booking->customer->name},")
                ->line("Reservamos {$service} para el {$when}.")
                ->line('Para confirmarla necesitamos que abones la seña.')
                ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer))
                ->line('Si no se abona la seña, el turno puede liberarse.');
        }

        return (new MailMessage)
            ->subject("Tu reserva en {$business} está confirmada")
            ->greeting("Hola {$this->booking->customer->name},")
            ->line("Confirmamos {$service} para el {$when}.")
            ->line("Te atiende {$this->booking->employee->name}.")
            ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
    }

    private function employeeMail(): MailMessage
    {
        return (new MailMessage)
            ->subject('Tenés una reserva nueva')
            ->greeting("Hola {$this->booking->employee->name},")
            ->line("{$this->booking->customer->name} reservó {$this->service()->name} para el {$this->formatDateTime()}.")
            ->action('Ver la reserva', $this->actionUrl(NotificationAudience::Employee));
    }
}
```

- [ ] **Step 4: Crear el listener**

`app/Listeners/SendBookingCreatedNotifications.php`:

```php
<?php

namespace App\Listeners;

use App\Enums\NotificationAudience;
use App\Events\BookingCreated;
use App\Notifications\Bookings\BookingCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingCreatedNotifications implements ShouldQueue
{
    public function handle(BookingCreated $event): void
    {
        $booking = $event->booking;

        $booking->customer->notify(new BookingCreatedNotification($booking, NotificationAudience::Customer));
        $booking->employee->notify(new BookingCreatedNotification($booking, NotificationAudience::Employee));
    }
}
```

Laravel descubre el listener solo por el type-hint de `handle()`; no hay que registrarlo en ningún provider.

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=BookingCreatedNotificationTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Correr la suite completa**

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS. Este es el chequeo importante de la tarea: todos los tests que crean reservas por `CreateBooking` sin fakear ahora envían notificaciones de verdad al mailer `array` y escriben en `notifications`.

Si algo falla acá, arreglarlo antes de seguir. Los sospechosos son `tests/Feature/Bookings/CreateBookingTest.php`, `tests/Feature/Bookings/BookingConcurrencyTest.php`, `tests/Feature/Dashboard/BookingsTest.php` y `tests/Feature/Public/BusinessBookingTest.php`.

- [ ] **Step 7: Formatear y commitear**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

```bash
git add app/Notifications/Bookings/BookingCreatedNotification.php app/Listeners/SendBookingCreatedNotifications.php tests/Feature/Notifications/BookingCreatedNotificationTest.php
git commit -m "feat: notify customer and employee when a booking is created"
```

---

### Task 4: Notificación de reserva confirmada

**Files:**
- Create: `app/Events/BookingConfirmed.php`
- Create: `app/Notifications/Bookings/BookingConfirmedNotification.php`
- Create: `app/Listeners/SendBookingConfirmedNotifications.php`
- Modify: `app/Actions/Bookings/ConfirmBooking.php`
- Test: `tests/Feature/Notifications/BookingConfirmedNotificationTest.php`

**Interfaces:**
- Consumes: `BookingNotification`, `NotificationAudience` (Task 2).
- Produces: `App\Events\BookingConfirmed` con `public readonly Booking $booking`. `BookingConfirmedNotification::__construct(Booking $booking)`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Notifications/BookingConfirmedNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Notifications;

use App\Actions\Bookings\ConfirmBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingConfirmedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingConfirmedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingBooking(): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => BookingStatus::Pending,
        ]);
    }

    public function test_confirming_notifies_the_customer(): void
    {
        Notification::fake();
        $booking = $this->makePendingBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        app(ConfirmBooking::class)->handle($booking, $owner);

        Notification::assertSentTo($booking->customer, BookingConfirmedNotification::class);
    }

    public function test_confirming_does_not_notify_the_employee(): void
    {
        Notification::fake();
        $booking = $this->makePendingBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        app(ConfirmBooking::class)->handle($booking, $owner);

        Notification::assertNotSentTo($booking->employee, BookingConfirmedNotification::class);
    }

    public function test_the_event_carries_the_already_confirmed_booking(): void
    {
        Notification::fake();
        $booking = $this->makePendingBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        app(ConfirmBooking::class)->handle($booking, $owner);

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingConfirmedNotification $notification) => $notification->booking->status === BookingStatus::Confirmed,
        );
    }

    public function test_the_mail_and_payload_describe_the_confirmation(): void
    {
        $booking = $this->makePendingBooking();
        $booking->update(['status' => BookingStatus::Confirmed]);
        $notification = new BookingConfirmedNotification($booking->fresh());

        $mail = $notification->toMail($booking->customer);
        $payload = $notification->toArray($booking->customer);

        $this->assertStringContainsString('confirmada', $mail->subject);
        $this->assertSame('booking.confirmed', $payload['type']);
        $this->assertSame($booking->id, $payload['booking_id']);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BookingConfirmedNotificationTest`
Expected: FAIL con `Class "App\Notifications\Bookings\BookingConfirmedNotification" not found`.

- [ ] **Step 3: Crear el evento**

`app/Events/BookingConfirmed.php`:

```php
<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
```

- [ ] **Step 4: Disparar el evento desde la Action**

En `app/Actions/Bookings/ConfirmBooking.php`, reemplazar el `return $booking->fresh();` final por:

```php
        $booking = $booking->fresh();

        event(new BookingConfirmed($booking));

        return $booking;
```

y agregar `use App\Events\BookingConfirmed;` a los imports. El evento lleva la instancia fresca, ya en estado `confirmed`.

- [ ] **Step 5: Crear la notificación**

`app/Notifications/Bookings/BookingConfirmedNotification.php`:

```php
<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use Illuminate\Notifications\Messages\MailMessage;

class BookingConfirmedNotification extends BookingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tu reserva en {$this->booking->business->name} está confirmada")
            ->greeting("Hola {$this->booking->customer->name},")
            ->line("Ya está confirmada tu reserva de {$this->service()->name} para el {$this->formatDateTime()}.")
            ->line("Te atiende {$this->booking->employee->name}.")
            ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + ['type' => 'booking.confirmed'];
    }
}
```

- [ ] **Step 6: Crear el listener**

`app/Listeners/SendBookingConfirmedNotifications.php`:

```php
<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Notifications\Bookings\BookingConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingConfirmedNotifications implements ShouldQueue
{
    public function handle(BookingConfirmed $event): void
    {
        $event->booking->customer->notify(new BookingConfirmedNotification($event->booking));
    }
}
```

- [ ] **Step 7: Correr los tests y verificar que pasan**

Run: `docker compose exec laravel.test php artisan test --filter=BookingConfirmedNotificationTest`
Expected: PASS, 4 tests.

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS. Revisar en particular `tests/Feature/Bookings/BookingStatusTransitionsTest.php` y `tests/Feature/Dashboard/BookingsTest.php`.

- [ ] **Step 8: Formatear y commitear**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

```bash
git add app/Events/BookingConfirmed.php app/Notifications/Bookings/BookingConfirmedNotification.php app/Listeners/SendBookingConfirmedNotifications.php app/Actions/Bookings/ConfirmBooking.php tests/Feature/Notifications/BookingConfirmedNotificationTest.php
git commit -m "feat: notify the customer when a booking is confirmed"
```

---

### Task 5: Notificación de reserva reprogramada

Incluye la reestructuración de `RescheduleBooking` para que el evento salga después del commit.

**Files:**
- Create: `app/Events/BookingRescheduled.php`
- Create: `app/Notifications/Bookings/BookingRescheduledNotification.php`
- Create: `app/Listeners/SendBookingRescheduledNotifications.php`
- Modify: `app/Actions/Bookings/RescheduleBooking.php`
- Test: `tests/Feature/Notifications/BookingRescheduledNotificationTest.php`

**Interfaces:**
- Consumes: `BookingNotification`, `NotificationAudience` (Task 2).
- Produces: `App\Events\BookingRescheduled` con `public readonly Booking $booking` y `public readonly CarbonImmutable $previousStartsAt`. `BookingRescheduledNotification::__construct(Booking $booking, CarbonImmutable $previousStartsAt, NotificationAudience $audience)` con las propiedades públicas `readonly` `$previousStartsAt` y `$audience`.

**Cuidado:** la nota del historial tiene que seguir siendo exactamente `"Reprogramado de {Y-m-d H:i} a {Y-m-d H:i}"`, en la zona horaria del negocio. `tests/Feature/Bookings/RescheduleBookingTest.php:215` la valida con `assertStringContainsString`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Notifications/BookingRescheduledNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Notifications;

use App\Actions\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingRescheduledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingRescheduledNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{booking: Booking, newStart: CarbonImmutable}
     */
    private function makeReschedulableBooking(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create([
            'name' => 'Corte de pelo',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
        ]);
        $customer = User::factory()->customer()->create();
        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        $service->employees()->attach($employee->id);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $monday->setTime(9, 0),
            'ends_at' => $monday->setTime(9, 30),
        ]);

        return ['booking' => $booking, 'newStart' => $monday->setTime(10, 0)];
    }

    public function test_rescheduling_notifies_the_customer_and_the_employee(): void
    {
        Notification::fake();
        ['booking' => $booking, 'newStart' => $newStart] = $this->makeReschedulableBooking();
        $previousStart = $booking->starts_at->copy();

        app(RescheduleBooking::class)->handle(
            $booking,
            ['starts_at' => $newStart->toDateTimeString()],
            $booking->customer,
        );

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingRescheduledNotification $notification) => $notification->audience === NotificationAudience::Customer
                && $notification->previousStartsAt->equalTo($previousStart)
                && $notification->booking->starts_at->equalTo($newStart),
        );
        Notification::assertSentTo(
            $booking->employee,
            fn (BookingRescheduledNotification $notification) => $notification->audience === NotificationAudience::Employee,
        );
    }

    public function test_the_status_history_note_keeps_its_format(): void
    {
        Notification::fake();
        ['booking' => $booking, 'newStart' => $newStart] = $this->makeReschedulableBooking();
        $previousStart = $booking->starts_at->copy();

        app(RescheduleBooking::class)->handle(
            $booking,
            ['starts_at' => $newStart->toDateTimeString()],
            $booking->customer,
        );

        $note = $booking->statusHistories()->latest('id')->first()->notes;

        $this->assertSame(
            "Reprogramado de {$previousStart->format('Y-m-d H:i')} a {$newStart->format('Y-m-d H:i')}.",
            $note,
        );
    }

    public function test_the_mail_mentions_both_times(): void
    {
        ['booking' => $booking, 'newStart' => $newStart] = $this->makeReschedulableBooking();
        $previousStart = $booking->starts_at->copy();

        $notification = new BookingRescheduledNotification(
            $booking,
            CarbonImmutable::parse($previousStart),
            NotificationAudience::Customer,
        );
        $mail = $notification->toMail($booking->customer);
        $payload = $notification->toArray($booking->customer);

        $body = implode(' ', $mail->introLines);
        $this->assertStringContainsString('reprogram', mb_strtolower($mail->subject));
        $this->assertStringContainsString($previousStart->format('H:i'), $body);
        $this->assertSame('booking.rescheduled', $payload['type']);
        $this->assertSame($previousStart->toIso8601String(), $payload['previous_starts_at']);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BookingRescheduledNotificationTest`
Expected: FAIL con `Class "App\Notifications\Bookings\BookingRescheduledNotification" not found`.

- [ ] **Step 3: Crear el evento**

`app/Events/BookingRescheduled.php`:

```php
<?php

namespace App\Events;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

class BookingRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
    ) {}
}
```

- [ ] **Step 4: Reestructurar `RescheduleBooking`**

En `app/Actions/Bookings/RescheduleBooking.php`, agregar `use App\Events\BookingRescheduled;` y reemplazar el bloque que va desde `$oldStart = ...` hasta el cierre de `handle()` por:

```php
        $previousStartsAt = CarbonImmutable::parse($booking->starts_at)->setTimezone($business->timezone);
        $newStart = CarbonImmutable::parse($data['starts_at'])->setTimezone($business->timezone);
        $newEnd = $newStart->addMinutes($service->duration_minutes);

        $booking = DB::transaction(function () use ($business, $service, $employee, $booking, $newStart, $newEnd, $previousStartsAt, $actingUser) {
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['booking-employee-'.$employee->id]);

            if ($newStart->lt(CarbonImmutable::now($business->timezone))) {
                throw ValidationException::withMessages(['starts_at' => 'No se puede reprogramar a un horario que ya pasó.']);
            }

            $available = collect($this->availabilityService->getAvailableSlots($business, $service, $employee, $newStart, excludeBookingId: $booking->id))
                ->contains(fn (array $slot) => $slot['starts_at']->equalTo($newStart));

            if (! $available) {
                throw ValidationException::withMessages(['starts_at' => 'Ese horario ya no está disponible.']);
            }

            $booking->update(['starts_at' => $newStart, 'ends_at' => $newEnd]);

            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => $booking->status,
                'to_status' => $booking->status,
                'changed_by' => $actingUser->id,
                'notes' => "Reprogramado de {$previousStartsAt->format('Y-m-d H:i')} a {$newStart->format('Y-m-d H:i')}.",
            ]);

            return $booking->fresh();
        });

        event(new BookingRescheduled($booking, $previousStartsAt));

        return $booking;
```

El único cambio de comportamiento es que `$oldStart` pasa de ser un string precalculado a un `CarbonImmutable` (`$previousStartsAt`) que también viaja en el evento, y que el `fresh()` ahora ocurre dentro de la transacción para que el evento salga con la reserva ya actualizada. El texto de la nota queda idéntico.

- [ ] **Step 5: Crear la notificación**

`app/Notifications/Bookings/BookingRescheduledNotification.php`:

```php
<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

class BookingRescheduledNotification extends BookingNotification
{
    public function __construct(
        Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
        public readonly NotificationAudience $audience,
    ) {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $before = $this->formatDateTime($this->previousStartsAt);
        $after = $this->formatDateTime();
        $service = $this->service()->name;

        if ($this->audience === NotificationAudience::Customer) {
            return (new MailMessage)
                ->subject("Reprogramamos tu reserva en {$this->booking->business->name}")
                ->greeting("Hola {$this->booking->customer->name},")
                ->line("Tu reserva de {$service} pasó del {$before} al {$after}.")
                ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
        }

        return (new MailMessage)
            ->subject('Se reprogramó una de tus reservas')
            ->greeting("Hola {$this->booking->employee->name},")
            ->line("La reserva de {$this->booking->customer->name} para {$service} pasó del {$before} al {$after}.")
            ->action('Ver la reserva', $this->actionUrl(NotificationAudience::Employee));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.rescheduled',
            'previous_starts_at' => $this->previousStartsAt->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 6: Crear el listener**

`app/Listeners/SendBookingRescheduledNotifications.php`:

```php
<?php

namespace App\Listeners;

use App\Enums\NotificationAudience;
use App\Events\BookingRescheduled;
use App\Notifications\Bookings\BookingRescheduledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingRescheduledNotifications implements ShouldQueue
{
    public function handle(BookingRescheduled $event): void
    {
        $booking = $event->booking;
        $previous = $event->previousStartsAt;

        $booking->customer->notify(new BookingRescheduledNotification($booking, $previous, NotificationAudience::Customer));
        $booking->employee->notify(new BookingRescheduledNotification($booking, $previous, NotificationAudience::Employee));
    }
}
```

- [ ] **Step 7: Correr los tests y verificar que pasan**

Run: `docker compose exec laravel.test php artisan test --filter=BookingRescheduledNotificationTest`
Expected: PASS, 3 tests.

Run: `docker compose exec laravel.test php artisan test --filter=RescheduleBookingTest`
Expected: PASS, sin cambios respecto de antes. Este es el chequeo de la reestructuración.

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS.

- [ ] **Step 8: Formatear y commitear**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

```bash
git add app/Events/BookingRescheduled.php app/Notifications/Bookings/BookingRescheduledNotification.php app/Listeners/SendBookingRescheduledNotifications.php app/Actions/Bookings/RescheduleBooking.php tests/Feature/Notifications/BookingRescheduledNotificationTest.php
git commit -m "feat: notify customer and employee when a booking is rescheduled"
```

---

### Task 6: Notificación de reserva cancelada

**Files:**
- Create: `app/Events/BookingCancelled.php`
- Create: `app/Notifications/Bookings/BookingCancelledNotification.php`
- Create: `app/Listeners/SendBookingCancelledNotifications.php`
- Modify: `app/Actions/Bookings/CancelBooking.php`
- Test: `tests/Feature/Notifications/BookingCancelledNotificationTest.php`

**Interfaces:**
- Consumes: `BookingNotification`, `NotificationAudience` (Task 2).
- Produces: `App\Events\BookingCancelled` con `public readonly Booking $booking` y `public readonly User $cancelledBy`. `BookingCancelledNotification::__construct(Booking $booking, User $cancelledBy, NotificationAudience $audience)`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Notifications/BookingCancelledNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Notifications;

use App\Actions\Bookings\CancelBooking;
use App\Enums\BookingStatus;
use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingCancelledNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfirmedBooking(): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'cancellation_hours' => 2]);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create(['name' => 'Ana'])->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
        ]);
    }

    public function test_cancelling_notifies_the_customer_and_the_employee(): void
    {
        Notification::fake();
        $booking = $this->makeConfirmedBooking();

        app(CancelBooking::class)->handle($booking, $booking->customer);

        Notification::assertSentTo($booking->customer, BookingCancelledNotification::class);
        Notification::assertSentTo($booking->employee, BookingCancelledNotification::class);
    }

    public function test_the_event_carries_the_already_cancelled_booking(): void
    {
        Notification::fake();
        $booking = $this->makeConfirmedBooking();

        app(CancelBooking::class)->handle($booking, $booking->customer);

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingCancelledNotification $notification) => $notification->booking->status === BookingStatus::Cancelled,
        );
    }

    public function test_the_customer_mail_changes_when_the_business_cancels(): void
    {
        $booking = $this->makeConfirmedBooking();
        $owner = User::factory()->create(['business_id' => $booking->business_id]);

        $byCustomer = (new BookingCancelledNotification($booking, $booking->customer, NotificationAudience::Customer))
            ->toMail($booking->customer);
        $byBusiness = (new BookingCancelledNotification($booking, $owner, NotificationAudience::Customer))
            ->toMail($booking->customer);

        $this->assertStringContainsString('Cancelaste', implode(' ', $byCustomer->introLines));
        $this->assertStringContainsString('canceló tu reserva', implode(' ', $byBusiness->introLines));
    }

    public function test_the_payload_records_who_cancelled(): void
    {
        $booking = $this->makeConfirmedBooking();

        $payload = (new BookingCancelledNotification($booking, $booking->customer, NotificationAudience::Employee))
            ->toArray($booking->employee);

        $this->assertSame('booking.cancelled', $payload['type']);
        $this->assertSame($booking->customer_id, $payload['cancelled_by']);
        $this->assertTrue($payload['cancelled_by_customer']);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BookingCancelledNotificationTest`
Expected: FAIL con `Class "App\Notifications\Bookings\BookingCancelledNotification" not found`.

- [ ] **Step 3: Crear el evento**

`app/Events/BookingCancelled.php`:

```php
<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly User $cancelledBy,
    ) {}
}
```

- [ ] **Step 4: Disparar el evento desde la Action**

En `app/Actions/Bookings/CancelBooking.php`, agregar `use App\Events\BookingCancelled;` y reemplazar el `return $booking->fresh();` final por:

```php
        $booking = $booking->fresh();

        event(new BookingCancelled($booking, $actingUser));

        return $booking;
```

- [ ] **Step 5: Crear la notificación**

`app/Notifications/Bookings/BookingCancelledNotification.php`:

```php
<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class BookingCancelledNotification extends BookingNotification
{
    public function __construct(
        Booking $booking,
        public readonly User $cancelledBy,
        public readonly NotificationAudience $audience,
    ) {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->formatDateTime();
        $service = $this->service()->name;
        $business = $this->booking->business->name;

        if ($this->audience === NotificationAudience::Customer) {
            $line = $this->cancelledByCustomer()
                ? "Cancelaste tu reserva de {$service} del {$when}."
                : "{$business} canceló tu reserva de {$service} del {$when}.";

            return (new MailMessage)
                ->subject("Se canceló tu reserva en {$business}")
                ->greeting("Hola {$this->booking->customer->name},")
                ->line($line)
                ->action('Ver mis reservas', $this->actionUrl(NotificationAudience::Customer));
        }

        $line = $this->cancelledByCustomer()
            ? "{$this->booking->customer->name} canceló su reserva de {$service} del {$when}."
            : "Se canceló la reserva de {$this->booking->customer->name} para {$service} del {$when}.";

        return (new MailMessage)
            ->subject('Se canceló una de tus reservas')
            ->greeting("Hola {$this->booking->employee->name},")
            ->line($line)
            ->action('Ver la agenda', $this->actionUrl(NotificationAudience::Employee));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.cancelled',
            'cancelled_by' => $this->cancelledBy->id,
            'cancelled_by_customer' => $this->cancelledByCustomer(),
        ];
    }

    private function cancelledByCustomer(): bool
    {
        return $this->cancelledBy->id === $this->booking->customer_id;
    }
}
```

- [ ] **Step 6: Crear el listener**

`app/Listeners/SendBookingCancelledNotifications.php`:

```php
<?php

namespace App\Listeners;

use App\Enums\NotificationAudience;
use App\Events\BookingCancelled;
use App\Notifications\Bookings\BookingCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingCancelledNotifications implements ShouldQueue
{
    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking;
        $by = $event->cancelledBy;

        $booking->customer->notify(new BookingCancelledNotification($booking, $by, NotificationAudience::Customer));
        $booking->employee->notify(new BookingCancelledNotification($booking, $by, NotificationAudience::Employee));
    }
}
```

- [ ] **Step 7: Correr los tests y verificar que pasan**

Run: `docker compose exec laravel.test php artisan test --filter=BookingCancelledNotificationTest`
Expected: PASS, 4 tests.

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS. `tests/Feature/Public/MyBookingsTest.php` es el que ejercita el camino web sin negocio ligado, así que confirma que el helper `service()` de Task 2 hace su trabajo.

- [ ] **Step 8: Formatear y commitear**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

```bash
git add app/Events/BookingCancelled.php app/Notifications/Bookings/BookingCancelledNotification.php app/Listeners/SendBookingCancelledNotifications.php app/Actions/Bookings/CancelBooking.php tests/Feature/Notifications/BookingCancelledNotificationTest.php
git commit -m "feat: notify customer and employee when a booking is cancelled"
```

---

### Task 7: Comando de recordatorios y scheduler

**Files:**
- Create: `app/Notifications/Bookings/BookingReminderNotification.php`
- Create: `app/Console/Commands/SendBookingReminders.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Notifications/BookingRemindersCommandTest.php`

**Interfaces:**
- Consumes: `ReminderType`, `BookingReminder`, `Booking::reminders()` (Task 1); `BookingNotification`, `NotificationAudience` (Task 2).
- Produces: comando `bookings:send-reminders`. `BookingReminderNotification::__construct(Booking $booking, ReminderType $type)`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Notifications/BookingRemindersCommandTest.php`:

```php
<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Bookings\BookingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(string $startsIn, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['name' => 'Corte de pelo']);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'service_id' => $service->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => User::factory()->employee()->create(['business_id' => $business->id])->id,
            'status' => $status,
            'starts_at' => now()->add($startsIn),
            'ends_at' => now()->add($startsIn)->addMinutes(30),
        ]);
    }

    public function test_it_sends_the_24h_reminder_inside_the_window(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('23 hours');

        $this->artisan('bookings:send-reminders')->assertExitCode(0);

        Notification::assertSentTo(
            $booking->customer,
            fn (BookingReminderNotification $notification) => $notification->type === ReminderType::TwentyFourHours,
        );
        $this->assertDatabaseHas('booking_reminders', [
            'booking_id' => $booking->id,
            'type' => '24h',
        ]);
    }

    public function test_it_does_not_send_the_24h_reminder_before_the_window(): void
    {
        Notification::fake();
        $this->makeBooking('30 hours');

        $this->artisan('bookings:send-reminders');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('booking_reminders', 0);
    }

    public function test_a_booking_within_two_hours_only_gets_the_2h_reminder(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('90 minutes');

        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 1);
        $this->assertDatabaseHas('booking_reminders', ['booking_id' => $booking->id, 'type' => '2h']);
        $this->assertDatabaseMissing('booking_reminders', ['booking_id' => $booking->id, 'type' => '24h']);
    }

    public function test_running_twice_sends_each_reminder_only_once(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('23 hours');

        $this->artisan('bookings:send-reminders');
        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 1);
        $this->assertDatabaseCount('booking_reminders', 1);
    }

    public function test_a_booking_gets_both_reminders_as_time_passes(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('23 hours');

        $this->artisan('bookings:send-reminders');
        $this->travel(22)->hours();
        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 2);
        $this->assertDatabaseCount('booking_reminders', 2);
    }

    public function test_it_skips_bookings_that_are_not_confirmed(): void
    {
        Notification::fake();
        $this->makeBooking('23 hours', BookingStatus::Pending);
        $this->makeBooking('23 hours', BookingStatus::Cancelled);
        $this->makeBooking('23 hours', BookingStatus::Completed);
        $this->makeBooking('23 hours', BookingStatus::NoShow);

        $this->artisan('bookings:send-reminders');

        Notification::assertNothingSent();
    }

    public function test_it_skips_bookings_that_already_started(): void
    {
        Notification::fake();
        $this->makeBooking('-1 hour');

        $this->artisan('bookings:send-reminders');

        Notification::assertNothingSent();
    }

    public function test_it_catches_up_on_a_reminder_whose_window_was_missed(): void
    {
        Notification::fake();
        // El turno está a 3 horas: la ventana de 24 h ya pasó sin que el comando corriera.
        $booking = $this->makeBooking('3 hours');

        $this->artisan('bookings:send-reminders');

        Notification::assertSentToTimes($booking->customer, BookingReminderNotification::class, 1);
        $this->assertDatabaseHas('booking_reminders', ['booking_id' => $booking->id, 'type' => '24h']);
    }

    public function test_it_covers_bookings_from_every_business_in_one_run(): void
    {
        Notification::fake();
        $first = $this->makeBooking('23 hours');
        $second = $this->makeBooking('23 hours');

        $this->assertNotSame($first->business_id, $second->business_id);

        $this->artisan('bookings:send-reminders');

        Notification::assertSentTo($first->customer, BookingReminderNotification::class);
        Notification::assertSentTo($second->customer, BookingReminderNotification::class);
    }

    public function test_the_reminder_mail_and_payload_name_the_window(): void
    {
        $booking = $this->makeBooking('23 hours');
        $notification = new BookingReminderNotification($booking, ReminderType::TwoHours);

        $mail = $notification->toMail($booking->customer);
        $payload = $notification->toArray($booking->customer);

        $this->assertStringContainsString('2 horas', implode(' ', $mail->introLines));
        $this->assertSame('booking.reminder', $payload['type']);
        $this->assertSame('2h', $payload['reminder']);
    }
}
```

Nota sobre `test_it_catches_up_on_a_reminder_whose_window_was_missed`: con el turno a 3 horas, la condición extra del tipo `24h` (`starts_at > now + 2h`) se cumple, así que sale el recordatorio de 24 h atrasado y no el de 2 h. Es exactamente el comportamiento de catch-up que se busca.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BookingRemindersCommandTest`
Expected: FAIL con `Class "App\Notifications\Bookings\BookingReminderNotification" not found`.

- [ ] **Step 3: Crear la notificación**

`app/Notifications/Bookings/BookingReminderNotification.php`:

```php
<?php

namespace App\Notifications\Bookings;

use App\Enums\NotificationAudience;
use App\Enums\ReminderType;
use App\Models\Booking;
use Illuminate\Notifications\Messages\MailMessage;

class BookingReminderNotification extends BookingNotification
{
    public function __construct(Booking $booking, public readonly ReminderType $type)
    {
        parent::__construct($booking);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $window = $this->type === ReminderType::TwentyFourHours ? '24 horas' : '2 horas';

        return (new MailMessage)
            ->subject("Recordatorio: tu turno en {$this->booking->business->name}")
            ->greeting("Hola {$this->booking->customer->name},")
            ->line("Te recordamos que faltan menos de {$window} para tu turno de {$this->service()->name}.")
            ->line("Es el {$this->formatDateTime()}, con {$this->booking->employee->name}.")
            ->action('Ver mi reserva', $this->actionUrl(NotificationAudience::Customer));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->basePayload() + [
            'type' => 'booking.reminder',
            'reminder' => $this->type->value,
        ];
    }
}
```

- [ ] **Step 4: Crear el comando**

`app/Console/Commands/SendBookingReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use App\Notifications\Bookings\BookingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Envía los recordatorios de 24 y 2 horas de las reservas confirmadas.';

    public function handle(): int
    {
        $sent = 0;

        foreach (ReminderType::cases() as $type) {
            foreach ($this->pendingBookings($type) as $booking) {
                if ($this->claim($booking, $type)) {
                    $booking->customer->notify(new BookingReminderNotification($booking, $type));
                    $sent++;
                }
            }
        }

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\LazyCollection<int, Booking>
     */
    private function pendingBookings(ReminderType $type)
    {
        $now = now();

        return Booking::withoutGlobalScope(BusinessScope::class)
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addHours($type->hoursBefore()))
            // Sin esta guarda, una reserva creada con poca antelación dispararía
            // los dos recordatorios en la misma corrida.
            ->when(
                $type === ReminderType::TwentyFourHours,
                fn ($query) => $query->where('starts_at', '>', $now->copy()->addHours(ReminderType::TwoHours->hoursBefore())),
            )
            ->whereDoesntHave('reminders', fn ($query) => $query->where('type', $type->value))
            ->with(['business', 'customer', 'employee'])
            ->orderBy('starts_at')
            ->cursor();
    }

    /**
     * Reclama el recordatorio antes de enviarlo. El índice único (booking_id, type)
     * hace que dos corridas simultáneas no puedan reclamar el mismo.
     */
    private function claim(Booking $booking, ReminderType $type): bool
    {
        return DB::table('booking_reminders')->insertOrIgnore([
            'booking_id' => $booking->id,
            'type' => $type->value,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
```

- [ ] **Step 5: Agendar el comando**

Reemplazar el contenido de `routes/console.php` por:

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

- [ ] **Step 6: Correr los tests y verificar que pasan**

Run: `docker compose exec laravel.test php artisan test --filter=BookingRemindersCommandTest`
Expected: PASS, 10 tests.

- [ ] **Step 7: Verificar que el comando quedó agendado**

Run: `docker compose exec laravel.test php artisan schedule:list`
Expected: una línea con `*/5 * * * *` y `bookings:send-reminders`.

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS.

- [ ] **Step 8: Formatear y commitear**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

```bash
git add app/Notifications/Bookings/BookingReminderNotification.php app/Console/Commands/SendBookingReminders.php routes/console.php tests/Feature/Notifications/BookingRemindersCommandTest.php
git commit -m "feat: send deduplicated 24h and 2h booking reminders on a schedule"
```

---

### Task 8: Worker de colas, scheduler y documentación

Cierra la fase: hasta acá las notificaciones son `ShouldQueue` pero nada consume la cola fuera de los tests.

**Files:**
- Modify: `compose.yaml`
- Modify: `.env.example`
- Modify: `app/Notifications/EmployeeInvited.php`
- Modify: `CLAUDE.md`
- Test: verificación manual, más la suite completa

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: servicios Docker `queue` y `scheduler`; `QUEUE_CONNECTION=redis` por defecto.

- [ ] **Step 1: Confirmar que la imagen de Sail acepta un `command`**

Run: `docker compose exec laravel.test tail -n 20 /usr/local/bin/start-container`
Expected: el script termina con un bloque `if [ $# -gt 0 ]; then ... exec "$@"` (o `exec gosu $WWWUSER "$@"`). Eso confirma que pasarle un `command` a la imagen lo ejecuta en vez de levantar supervisor.

Si no aparece ese bloque, usar `entrypoint: []` junto al `command` en los servicios nuevos del Step 2.

- [ ] **Step 2: Agregar los servicios a `compose.yaml`**

Insertar después del bloque `laravel.test`, antes de `pgsql`. El bloque `build` va repetido: si estos servicios declararan solo `image: sail-8.5/app`, Compose intentaría bajar esa imagen de un registry, donde no existe.

```yaml
    queue:
        build:
            context: './vendor/laravel/sail/runtimes/8.5'
            dockerfile: Dockerfile
            args:
                WWWGROUP: '${WWWGROUP}'
        image: 'sail-8.5/app'
        command: ['php', 'artisan', 'queue:work', '--tries=3', '--max-time=3600']
        extra_hosts:
            - 'host.docker.internal:host-gateway'
        environment:
            WWWUSER: '${WWWUSER}'
            LARAVEL_SAIL: 1
        volumes:
            - '.:/var/www/html'
        networks:
            - sail
        depends_on:
            - pgsql
            - redis
            - mailpit
    scheduler:
        build:
            context: './vendor/laravel/sail/runtimes/8.5'
            dockerfile: Dockerfile
            args:
                WWWGROUP: '${WWWGROUP}'
        image: 'sail-8.5/app'
        command: ['php', 'artisan', 'schedule:work']
        extra_hosts:
            - 'host.docker.internal:host-gateway'
        environment:
            WWWUSER: '${WWWUSER}'
            LARAVEL_SAIL: 1
        volumes:
            - '.:/var/www/html'
        networks:
            - sail
        depends_on:
            - pgsql
            - redis
            - mailpit
```

Ninguno de los dos publica puertos, así que no chocan con otra pila levantada desde otro worktree.

- [ ] **Step 3: Pasar la cola a Redis**

En `.env.example` y en `.env`, cambiar:

```
QUEUE_CONNECTION=database
```

por:

```
QUEUE_CONNECTION=redis
```

`REDIS_CLIENT=phpredis`, `REDIS_HOST=redis` y `REDIS_PORT=6379` ya están configurados. La tabla `jobs` se deja donde está; no molesta.

`phpunit.xml` ya fija `QUEUE_CONNECTION=sync`, así que los tests no cambian.

- [ ] **Step 4: Encolar la invitación de empleado**

En `app/Notifications/EmployeeInvited.php`: borrar el comentario que empieza con `// Sent synchronously (not ShouldQueue)`, agregar `use Illuminate\Contracts\Queue\ShouldQueue;` a los imports y cambiar la declaración de la clase por:

```php
class EmployeeInvited extends Notification implements ShouldQueue
```

- [ ] **Step 5: Correr la suite completa**

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS. `tests/Feature/Dashboard/EmployeeInvitationsTest.php` usa `Notification::fake()`, así que no le afecta el cambio de encolado.

- [ ] **Step 6: Verificación manual de punta a punta**

```bash
WWWUSER=1000 WWWGROUP=1000 docker compose up -d --build
docker compose exec laravel.test php artisan migrate --force
docker compose ps
```

Expected: `queue` y `scheduler` en estado `running`.

```bash
docker compose logs --tail=20 scheduler
```

Expected: líneas de `schedule:work` indicando que corrió `bookings:send-reminders`.

Después crear una reserva desde la interfaz y confirmar dos cosas:

```bash
docker compose logs --tail=30 queue
```

Expected: los jobs procesados aparecen como `App\Listeners\SendBookingCreatedNotifications ... DONE`.

Y abrir Mailpit en `http://localhost:8025` (o el `FORWARD_MAILPIT_DASHBOARD_PORT` del worktree): deben aparecer los dos mails, el del cliente y el del empleado.

```bash
docker compose exec laravel.test php artisan tinker --execute="echo \App\Models\Booking::withoutGlobalScope(\App\Models\Scopes\BusinessScope::class)->count();"
```

Verificar además que la tabla `notifications` tiene filas nuevas.

- [ ] **Step 7: Documentar en `CLAUDE.md`**

En la sección "Development environment: Docker (Laravel Sail)", después del párrafo que enumera los servicios de `compose.yaml`, agregar que la pila también define `queue` (`php artisan queue:work`) y `scheduler` (`php artisan schedule:work`), que la cola corre sobre Redis (`QUEUE_CONNECTION=redis`), y que los mails salientes se inspeccionan en el panel de Mailpit. Aclarar que al cambiar código de un listener o de una notificación hay que reiniciar el worker, porque `queue:work` mantiene el código en memoria:

```bash
docker compose restart queue
```

- [ ] **Step 8: Formatear y commitear**

Run: `docker compose exec laravel.test vendor/bin/pint --test`

```bash
git add compose.yaml .env.example app/Notifications/EmployeeInvited.php CLAUDE.md
git commit -m "feat: run queue worker and scheduler in Docker, move queue to Redis"
```

---

## Verificación final de la fase

- [ ] `docker compose exec laravel.test php artisan test` — toda la suite en verde.
- [ ] `docker compose exec laravel.test vendor/bin/pint --test` — sin problemas de estilo.
- [ ] `docker compose exec laravel.test php artisan schedule:list` — `bookings:send-reminders` cada cinco minutos.
- [ ] `docker compose ps` — `queue` y `scheduler` corriendo.
- [ ] Mailpit muestra los mails de creación, confirmación, reprogramación, cancelación y recordatorio.
- [ ] La tabla `notifications` acumula una fila por notificación enviada.
