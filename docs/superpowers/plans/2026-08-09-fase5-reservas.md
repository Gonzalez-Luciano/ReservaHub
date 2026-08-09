# Fase 5 — Reservas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the booking engine (`CreateBooking`, `ConfirmBooking`, `CancelBooking`, `RescheduleBooking`, `CompleteBooking`, `MarkNoShow` Actions, transactional overlap-safety, `booking_status_histories`, `BookingPolicy`, `BookingCreated` event) plus the two UI surfaces that consume it: a dashboard for business staff and a public self-service page for customers, both reusing `App\Services\AvailabilityService` from Fase 4.

**Architecture:** Actions live in `app/Actions/Bookings/`, one per use case, each wrapping its write in `DB::transaction()` where a slot is being claimed (`CreateBooking`, `RescheduleBooking`) and re-validating with `AvailabilityService` *inside* that transaction, serialized per-employee via a Postgres advisory lock (`pg_advisory_xact_lock`) so two concurrent requests for the same slot cannot both succeed — `SELECT ... FOR UPDATE` alone doesn't work here because an empty slot has no row to lock. Every status change writes one `booking_status_histories` row. Two independent controller/route/UI stacks call the same Actions: `Dashboard\BookingController` (staff, under the existing `business` middleware) and `Public\BookingController` / `Public\MyBookingsController` (customers, resolved by business `slug`, no tenant middleware since a customer is not tied to one business).

**Tech Stack:** Laravel 13 (PHP 8.3), PostgreSQL (required — `pg_advisory_xact_lock` is Postgres-specific; confirmed `DB_CONNECTION=pgsql` in both `.env` and the `testing` database), PHPUnit, Carbon/CarbonImmutable, Inertia 3 + React (JSX, Tailwind utility classes), Pint.

## Global Constraints

- Spec of record: `docs/superpowers/specs/2026-08-09-fase5-reservas-design.md` — every task below implements one of its sections.
- Every query on a tenant table must be scoped by `business_id` (project rule, `CLAUDE.md`, enforced here by `App\Models\Scopes\BusinessScope`, which throws `MissingBusinessContextException` outside console if `Business::class` isn't bound in the container). Follow the existing pattern from `App\Services\AvailabilityService::getAvailableSlots()`: bind `app()->instance(Business::class, $business)` at the top of any Action that queries tenant-scoped models, don't rely solely on the calling middleware having already bound it.
- Booking `duration` and `price`/`deposit_amount` are **always** derived server-side from the `Service` at creation time — never accepted from client input (project rule, `CLAUDE.md`).
- Domain rule violations (slot no longer available, invalid state transition, cancellation too late) are raised as `Illuminate\Validation\ValidationException::withMessages([...])` directly from Actions, in Spanish — this is the established convention in this codebase (see `App\Actions\Employees\SyncEmployeeServices`), not a custom exception hierarchy.
- Follow existing conventions exactly: enums in `App\Enums`, migrations use `foreignId(...)->constrained()->cascadeOnDelete()` (or `nullOnDelete()` where the spec calls for it), models use the `#[Fillable([...])]` attribute, `BelongsToBusiness` trait on tenant-scoped models only (a model using it must **not** put `business_id` in its own `#[Fillable]` list — the trait's `creating()` hook sets it directly from `Business::current()`), Form Requests define `authorize(): bool` with the actual role check (not a stub deferring to controller-only checks — see `app/Http/Requests/Dashboard/ServiceRequest.php`), Actions are plain classes with a `handle()` method, PHPUnit `TestCase` classes (not Pest), Inertia pages under `resources/js/Pages`, routes grouped by concern in `routes/*.php` and required from `routes/web.php`.
- `users` (and therefore `Booking.customer_id`) has no tenant global scope — a customer is not tied to one business. Any query resolving a `Booking` by its own `customer_id` (the "mis reservas" surface) must bypass `BusinessScope` explicitly (`Booking::withoutGlobalScope(BusinessScope::class)->...`) rather than relying on route-model binding, which would otherwise throw `MissingBusinessContextException` since no single business is in context there.
- `AvailabilityService::getAvailableSlots()` (Fase 4) requires a bound `employee`; there is no "any available employee" search in this fase (Fase 4's own decision, unchanged here).
- Every `Run:` command that invokes `php artisan`, `vendor/bin/pint`, or `pnpm` (for `pnpm build` after JS changes, if verifying in browser) must go through Docker per `CLAUDE.md` — prefix with `docker compose exec laravel.test` for PHP/artisan/pint. `git` runs on the host normally. Run `WWWUSER=1000 WWWGROUP=1000 docker compose up -d` once before Task 1 if the stack isn't already up.

---

### Task 1: `AvailabilityService` — support excluding a booking (needed by reschedule)

**Files:**
- Modify: `app/Services/AvailabilityService.php`
- Modify: `tests/Unit/Services/AvailabilityServiceTest.php`

**Interfaces:**
- Consumes: existing `AvailabilityService::getAvailableSlots()` internals (Fase 4, unchanged behavior when the new parameter is omitted).
- Produces: `AvailabilityService::getAvailableSlots(Business $business, Service $service, User $employee, CarbonImmutable $date, ?int $excludeBookingId = null): array` — when `$excludeBookingId` is given, that booking's own row is excluded from the busy-span calculation. `RescheduleBooking` (Task 4) relies on this: without it, a booking being moved would always see its own current slot as "busy" and reschedule attempts near the original time would be wrongly rejected.

- [ ] **Step 1: Write the failing test**

Add to `AvailabilityServiceTest` (same file Fase 4 built; imports for `Booking`, `BookingStatus`, `Schedule`, `DayOfWeek` already present):

```php
    public function test_excludes_a_given_booking_id_from_the_busy_span_calculation(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $withoutExclusion = $this->service->getAvailableSlots($business, $service, $employee, $date);
        $withExclusion = $this->service->getAvailableSlots($business, $service, $employee, $date, excludeBookingId: $booking->id);

        $this->assertSame(['09:30'], array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $withoutExclusion));
        $this->assertSame(['09:00', '09:30'], array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $withExclusion));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: FAIL — `getAvailableSlots()` does not accept an `$excludeBookingId` argument.

- [ ] **Step 3: Update the service signature and busy-span query**

Change the method signature:

```php
    public function getAvailableSlots(Business $business, Service $service, User $employee, CarbonImmutable $date, ?int $excludeBookingId = null): array
```

Find the `$busySpans = Booking::query()...` block and add a `when()` clause right after `->where('ends_at', '>', $windowStart->utc())`:

```php
        $busySpans = Booking::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::Completed])
            ->where('starts_at', '<', $windowEnd->utc())
            ->where('ends_at', '>', $windowStart->utc())
            ->when($excludeBookingId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->with('service')
            ->get()
            ->map(fn (Booking $booking) => [
                $booking->starts_at->setTimezone($timezone),
                $booking->ends_at->setTimezone($timezone)->addMinutes($booking->service->buffer_minutes),
            ])
            ->all();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: PASS (13 tests — 12 from Fase 4 plus this one; the new parameter is optional so no existing call site or test breaks).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AvailabilityService.php tests/Unit/Services/AvailabilityServiceTest.php
git commit -m "feat: let AvailabilityService exclude a booking from its own busy-span check"
```

---

### Task 2: `booking_status_histories` table, model, factory

**Files:**
- Create: `database/migrations/2026_08_09_000001_create_booking_status_histories_table.php`
- Create: `app/Models/BookingStatusHistory.php`
- Create: `database/factories/BookingStatusHistoryFactory.php`
- Modify: `app/Models/Booking.php`
- Test: `tests/Feature/Tenancy/BookingStatusHistoriesSchemaTest.php`

**Interfaces:**
- Consumes: `App\Enums\BookingStatus` (Fase 4), `App\Models\Booking` (Fase 4), `App\Models\User`.
- Produces: `App\Models\BookingStatusHistory` (fillable `['booking_id', 'from_status', 'to_status', 'changed_by', 'notes']`; casts `from_status`/`to_status` → `BookingStatus`; relations `booking(): BelongsTo`, `changedBy(): BelongsTo` → `User`). `App\Models\Booking::statusHistories(): HasMany`. `Database\Factories\BookingStatusHistoryFactory`. Every Action task below (3, 4, 5, 6) inserts rows via `BookingStatusHistory::create([...])`.

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingStatusHistoriesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_status_histories_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('booking_status_histories'));
        $this->assertTrue(Schema::hasColumns('booking_status_histories', [
            'id', 'booking_id', 'from_status', 'to_status', 'changed_by', 'notes', 'created_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=BookingStatusHistoriesSchemaTest`
Expected: FAIL — table doesn't exist.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=BookingStatusHistoriesSchemaTest`
Expected: PASS

- [ ] **Step 5: Write the model and factory**

```php
<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Database\Factories\BookingStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'from_status', 'to_status', 'changed_by', 'notes'])]
class BookingStatusHistory extends Model
{
    /** @use HasFactory<BookingStatusHistoryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_status' => BookingStatus::class,
            'to_status' => BookingStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
```

`created_at` has no default in the migration, so set it explicitly on create (`$this->attributes['created_at'] ??= now()` is unnecessary — instead pass it explicitly wherever the model is created, starting in Task 3). Add the boot hook here so every Action gets it for free without repeating `'created_at' => now()` everywhere:

```php
    protected static function booted(): void
    {
        static::creating(function (self $history) {
            $history->created_at ??= now();
        });
    }
```

(Add this method to the class above, alongside `casts()`.)

```php
<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingStatusHistory>
 */
class BookingStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'from_status' => null,
            'to_status' => BookingStatus::Pending,
            'changed_by' => User::factory(),
            'notes' => null,
            'created_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Add the `statusHistories()` relation to `Booking`**

In `app/Models/Booking.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` and the method:

```php
    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }
```

- [ ] **Step 7: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_09_000001_create_booking_status_histories_table.php app/Models/BookingStatusHistory.php app/Models/Booking.php database/factories/BookingStatusHistoryFactory.php tests/Feature/Tenancy/BookingStatusHistoriesSchemaTest.php
git commit -m "feat: add booking_status_histories table, model and factory"
```

---

### Task 3: `BookingCreated` event + `CreateBooking` Action

**Files:**
- Create: `app/Events/BookingCreated.php`
- Create: `app/Actions/Bookings/CreateBooking.php`
- Test: `tests/Feature/Bookings/CreateBookingTest.php`

**Interfaces:**
- Consumes: `App\Services\AvailabilityService::getAvailableSlots()` (Task 1), `App\Models\{Booking,BookingStatusHistory,Business,Service,User}`, `App\Enums\{BookingStatus,Role}`.
- Produces: `App\Events\BookingCreated` (public readonly `Booking $booking`). `App\Actions\Bookings\CreateBooking::handle(Business $business, array $data, User $actingUser): Booking` where `$data` is `['customer_id' => int, 'employee_id' => int, 'service_id' => int, 'starts_at' => string, 'source' => string, 'notes' => ?string]`. Every later Action task and both controllers (Task 8, Task 10) depend on this exact signature.

- [ ] **Step 1: Write the event**

```php
<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCreated
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking)
    {
    }
}
```

- [ ] **Step 2: Write the failing tests**

```php
<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CreateBooking;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateBookingTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    private function setUpBusinessWithSchedule(?float $depositAmount = null): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create([
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 50,
            'deposit_amount' => $depositAmount,
        ]);
        $customer = User::factory()->customer()->create();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        app()->instance(Business::class, $business);

        return compact('business', 'employee', 'service', 'customer');
    }

    public function test_creates_a_confirmed_booking_when_service_has_no_deposit(): void
    {
        Event::fake();
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $slot = $this->nextMonday()->setTime(9, 0);

        $booking = app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('09:00', $booking->starts_at->format('H:i'));
        $this->assertSame('09:30', $booking->ends_at->format('H:i'));
        $this->assertSame('50.00', $booking->price);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'from_status' => null,
            'to_status' => 'confirmed',
            'changed_by' => $customer->id,
        ]);
        Event::assertDispatched(BookingCreated::class, fn (BookingCreated $event) => $event->booking->is($booking));
    }

    public function test_creates_a_pending_booking_when_service_requires_a_deposit(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule(depositAmount: 10);
        $slot = $this->nextMonday()->setTime(9, 0);

        $booking = app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);

        $this->assertSame(BookingStatus::Pending, $booking->status);
    }

    public function test_rejects_a_slot_already_taken_by_another_booking(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $slot = $this->nextMonday()->setTime(9, 0);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $slot,
            'ends_at' => $slot->addMinutes(30),
        ]);

        $this->expectException(ValidationException::class);

        app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);
    }

    public function test_rejects_a_slot_outside_working_hours(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $outsideHours = $this->nextMonday()->setTime(20, 0);

        $this->expectException(ValidationException::class);

        app(CreateBooking::class)->handle($business, [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $outsideHours->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ], $customer);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=CreateBookingTest`
Expected: FAIL — `App\Actions\Bookings\CreateBooking` not found.

- [ ] **Step 4: Write the Action**

```php
<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBooking
{
    public function __construct(private readonly AvailabilityService $availabilityService)
    {
    }

    /**
     * @param  array{customer_id: int, employee_id: int, service_id: int, starts_at: string, source: string, notes?: string|null}  $data
     */
    public function handle(Business $business, array $data, User $actingUser): Booking
    {
        app()->instance(Business::class, $business);

        $service = Service::findOrFail($data['service_id']);
        $employee = User::where('business_id', $business->id)->where('role', Role::Employee)->findOrFail($data['employee_id']);
        $customer = User::where('role', Role::Customer)->findOrFail($data['customer_id']);

        if ($service->business_id !== $business->id) {
            throw ValidationException::withMessages(['service_id' => 'El servicio no pertenece a este negocio.']);
        }

        $startsAt = CarbonImmutable::parse($data['starts_at'])->setTimezone($business->timezone);
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        $booking = DB::transaction(function () use ($business, $service, $employee, $customer, $startsAt, $endsAt, $data, $actingUser) {
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', [(string) $employee->id]);

            $available = collect($this->availabilityService->getAvailableSlots($business, $service, $employee, $startsAt))
                ->contains(fn (array $slot) => $slot['starts_at']->equalTo($startsAt));

            if (! $available) {
                throw ValidationException::withMessages(['starts_at' => 'Ese horario ya no está disponible.']);
            }

            $status = $service->deposit_amount > 0 ? BookingStatus::Pending : BookingStatus::Confirmed;

            $booking = Booking::create([
                'customer_id' => $customer->id,
                'employee_id' => $employee->id,
                'service_id' => $service->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status,
                'price' => $service->price,
                'deposit_amount' => $service->deposit_amount,
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'],
            ]);

            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => null,
                'to_status' => $status,
                'changed_by' => $actingUser->id,
            ]);

            return $booking;
        });

        event(new BookingCreated($booking));

        return $booking;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=CreateBookingTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Events/BookingCreated.php app/Actions/Bookings/CreateBooking.php tests/Feature/Bookings/CreateBookingTest.php
git commit -m "feat: add CreateBooking action with transactional overlap-safety"
```

---

### Task 4: `RescheduleBooking` Action

**Files:**
- Create: `app/Actions/Bookings/RescheduleBooking.php`
- Test: `tests/Feature/Bookings/RescheduleBookingTest.php`

**Interfaces:**
- Consumes: `AvailabilityService::getAvailableSlots(..., ?int $excludeBookingId)` (Task 1), `App\Models\{Booking,BookingStatusHistory}`, `App\Enums\BookingStatus`.
- Produces: `App\Actions\Bookings\RescheduleBooking::handle(Booking $booking, array $data, User $actingUser): Booking` where `$data = ['starts_at' => string]`. Consumed by `Dashboard\BookingController` (Task 8) and `Public\MyBookingsController` (Task 12).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RescheduleBookingTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_moves_the_booking_to_a_new_available_slot(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $rescheduled = app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);

        $this->assertSame('09:30', $rescheduled->starts_at->format('H:i'));
        $this->assertSame('10:00', $rescheduled->ends_at->format('H:i'));
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'from_status' => 'confirmed',
            'to_status' => 'confirmed',
        ]);
    }

    public function test_no_op_reschedule_to_its_own_current_slot_succeeds(): void
    {
        // Without excluding the booking's own row from the busy-span check, this would
        // always fail: the booking currently occupies exactly the slot it's "moving" to.
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 0),
        ]);

        $rescheduled = app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);

        $this->assertSame('09:30', $rescheduled->starts_at->format('H:i'));
    }

    public function test_rejects_reschedule_to_an_occupied_slot(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);
        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 0),
        ]);

        $this->expectException(ValidationException::class);

        app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);
    }

    public function test_rejects_rescheduling_a_cancelled_booking(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        $booking = Booking::factory()->cancelled()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $this->expectException(ValidationException::class);

        app(RescheduleBooking::class)->handle($booking, [
            'starts_at' => $date->setTime(9, 30)->toIso8601String(),
        ], $customer);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=RescheduleBookingTest`
Expected: FAIL — `App\Actions\Bookings\RescheduleBooking` not found.

- [ ] **Step 3: Write the Action**

```php
<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleBooking
{
    public function __construct(private readonly AvailabilityService $availabilityService)
    {
    }

    /**
     * @param  array{starts_at: string}  $data
     */
    public function handle(Booking $booking, array $data, User $actingUser): Booking
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => 'Esta reserva no puede reprogramarse desde su estado actual.']);
        }

        $business = $booking->business;
        app()->instance(Business::class, $business);

        $service = $booking->service;
        $employee = $booking->employee;
        $oldStart = $booking->starts_at->format('Y-m-d H:i');
        $newStart = CarbonImmutable::parse($data['starts_at'])->setTimezone($business->timezone);
        $newEnd = $newStart->addMinutes($service->duration_minutes);

        return DB::transaction(function () use ($business, $service, $employee, $booking, $newStart, $newEnd, $oldStart, $actingUser) {
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', [(string) $employee->id]);

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
                'notes' => "Reprogramado de {$oldStart} a {$newStart->format('Y-m-d H:i')}.",
            ]);

            return $booking->fresh();
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=RescheduleBookingTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Bookings/RescheduleBooking.php tests/Feature/Bookings/RescheduleBookingTest.php
git commit -m "feat: add RescheduleBooking action"
```

---

### Task 5: `CancelBooking` Action

**Files:**
- Create: `app/Actions/Bookings/CancelBooking.php`
- Test: `tests/Feature/Bookings/CancelBookingTest.php`

**Interfaces:**
- Consumes: `App\Models\{Booking,BookingStatusHistory}`, `App\Enums\{BookingStatus,Role}`.
- Produces: `App\Actions\Bookings\CancelBooking::handle(Booking $booking, User $actingUser): Booking`. Consumed by `Dashboard\BookingController` (Task 8) and `Public\MyBookingsController` (Task 12).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CancelBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CancelBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_staff_can_cancel_a_pending_booking(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24]);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $cancelled = app(CancelBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'to_status' => 'cancelled',
            'changed_by' => $staff->id,
        ]);
    }

    public function test_customer_can_cancel_within_the_cancellation_window(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(3)->addMinutes(30),
        ]);

        $cancelled = app(CancelBooking::class)->handle($booking, $customer);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
    }

    public function test_customer_cannot_cancel_inside_the_cancellation_window(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addHours(2),
            'ends_at' => CarbonImmutable::now('UTC')->addHours(2)->addMinutes(30),
        ]);

        $this->expectException(ValidationException::class);

        app(CancelBooking::class)->handle($booking, $customer);
    }

    public function test_staff_can_cancel_inside_the_cancellation_window(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addHours(2),
            'ends_at' => CarbonImmutable::now('UTC')->addHours(2)->addMinutes(30),
        ]);

        $cancelled = app(CancelBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
    }

    public function test_rejects_cancelling_an_already_completed_booking(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->completed()->create(['business_id' => $business->id]);

        $this->expectException(ValidationException::class);

        app(CancelBooking::class)->handle($booking, $staff);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=CancelBookingTest`
Expected: FAIL — `App\Actions\Bookings\CancelBooking` not found.

- [ ] **Step 3: Write the Action**

```php
<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CancelBooking
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => 'Esta reserva no puede cancelarse desde su estado actual.']);
        }

        if ($actingUser->role === Role::Customer) {
            $business = $booking->business;
            $cutoff = $booking->starts_at->subHours($business->cancellation_hours);

            if (CarbonImmutable::now($business->timezone)->greaterThan($cutoff)) {
                throw ValidationException::withMessages([
                    'starts_at' => "No podés cancelar esta reserva; faltan menos de {$business->cancellation_hours} horas para el turno.",
                ]);
            }
        }

        $fromStatus = $booking->status;
        $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => $fromStatus,
            'to_status' => BookingStatus::Cancelled,
            'changed_by' => $actingUser->id,
        ]);

        return $booking->fresh();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=CancelBookingTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Bookings/CancelBooking.php tests/Feature/Bookings/CancelBookingTest.php
git commit -m "feat: add CancelBooking action with cancellation_hours rule"
```

---

### Task 6: `ConfirmBooking`, `CompleteBooking`, `MarkNoShow` Actions

**Files:**
- Create: `app/Actions/Bookings/ConfirmBooking.php`
- Create: `app/Actions/Bookings/CompleteBooking.php`
- Create: `app/Actions/Bookings/MarkNoShow.php`
- Test: `tests/Feature/Bookings/BookingStatusTransitionsTest.php`

**Interfaces:**
- Consumes: `App\Models\{Booking,BookingStatusHistory}`, `App\Enums\BookingStatus`.
- Produces: `App\Actions\Bookings\ConfirmBooking::handle(Booking $booking, User $actingUser): Booking` (`pending → confirmed`), `App\Actions\Bookings\CompleteBooking::handle(Booking $booking, User $actingUser): Booking` (`confirmed → completed`), `App\Actions\Bookings\MarkNoShow::handle(Booking $booking, User $actingUser): Booking` (`confirmed → no_show`). Consumed by `Dashboard\BookingController` (Task 8).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CompleteBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\MarkNoShow;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingStatusTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private function staffFor(Business $business): User
    {
        return User::factory()->employee()->create(['business_id' => $business->id]);
    }

    public function test_confirm_moves_pending_to_confirmed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $confirmed = app(ConfirmBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Confirmed, $confirmed->status);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
        ]);
    }

    public function test_confirm_rejects_a_booking_that_is_not_pending(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        $this->expectException(ValidationException::class);

        app(ConfirmBooking::class)->handle($booking, $staff);
    }

    public function test_complete_moves_confirmed_to_completed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        $completed = app(CompleteBooking::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::Completed, $completed->status);
    }

    public function test_complete_rejects_a_booking_that_is_not_confirmed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $this->expectException(ValidationException::class);

        app(CompleteBooking::class)->handle($booking, $staff);
    }

    public function test_mark_no_show_moves_confirmed_to_no_show(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        $noShow = app(MarkNoShow::class)->handle($booking, $staff);

        $this->assertSame(BookingStatus::NoShow, $noShow->status);
    }

    public function test_mark_no_show_rejects_a_booking_that_is_not_confirmed(): void
    {
        $business = Business::factory()->create();
        $staff = $this->staffFor($business);
        $booking = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);

        $this->expectException(ValidationException::class);

        app(MarkNoShow::class)->handle($booking, $staff);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=BookingStatusTransitionsTest`
Expected: FAIL — none of the three Actions exist yet.

- [ ] **Step 3: Write the three Actions**

```php
<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConfirmBooking
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Solo una reserva pendiente puede confirmarse.']);
        }

        $booking->update(['status' => BookingStatus::Confirmed]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Confirmed,
            'changed_by' => $actingUser->id,
        ]);

        return $booking->fresh();
    }
}
```

```php
<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CompleteBooking
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => 'Solo una reserva confirmada puede completarse.']);
        }

        $booking->update(['status' => BookingStatus::Completed]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Confirmed,
            'to_status' => BookingStatus::Completed,
            'changed_by' => $actingUser->id,
        ]);

        return $booking->fresh();
    }
}
```

```php
<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MarkNoShow
{
    public function handle(Booking $booking, User $actingUser): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => 'Solo una reserva confirmada puede marcarse como ausencia.']);
        }

        $booking->update(['status' => BookingStatus::NoShow]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Confirmed,
            'to_status' => BookingStatus::NoShow,
            'changed_by' => $actingUser->id,
        ]);

        return $booking->fresh();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=BookingStatusTransitionsTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Bookings/ConfirmBooking.php app/Actions/Bookings/CompleteBooking.php app/Actions/Bookings/MarkNoShow.php tests/Feature/Bookings/BookingStatusTransitionsTest.php
git commit -m "feat: add ConfirmBooking, CompleteBooking and MarkNoShow actions"
```

---

### Task 7: `BookingPolicy`

**Files:**
- Create: `app/Policies/BookingPolicy.php`
- Test: `tests/Unit/Policies/BookingPolicyTest.php`

**Interfaces:**
- Consumes: `App\Models\{Booking,Business,User}`, `App\Enums\{Role,BookingStatus}`.
- Produces: `App\Policies\BookingPolicy` with methods `view(User, Booking): bool`, `createByStaff(User, Business): bool`, `createByCustomer(User): bool`, `cancel(User, Booking): bool`, `reschedule(User, Booking): bool`, `confirm(User, Booking): bool`, `complete(User, Booking): bool`, `markNoShow(User, Booking): bool`. Consumed by `Dashboard\BookingController` and `Public` controllers (Tasks 8, 10, 12) via `$this->authorize(...)`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Policies;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use App\Policies\BookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private BookingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new BookingPolicy;
    }

    public function test_customer_can_view_their_own_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        $this->assertTrue($this->policy->view($customer, $booking));
    }

    public function test_customer_cannot_view_someone_elses_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create();

        $this->assertFalse($this->policy->view($customer, $booking));
    }

    public function test_staff_can_view_a_booking_of_their_own_business(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->create(['business_id' => $business->id]);

        $this->assertTrue($this->policy->view($staff, $booking));
    }

    public function test_staff_cannot_view_a_booking_of_another_business(): void
    {
        $staff = User::factory()->employee()->create();
        $booking = Booking::factory()->create();

        $this->assertFalse($this->policy->view($staff, $booking));
    }

    public function test_view_any_allows_staff_of_the_business_and_rejects_others(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $outsider = User::factory()->employee()->create();
        $customer = User::factory()->customer()->create();

        $this->assertTrue($this->policy->viewAny($staff, $business));
        $this->assertFalse($this->policy->viewAny($outsider, $business));
        $this->assertFalse($this->policy->viewAny($customer, $business));
    }

    public function test_any_business_staff_role_can_create_by_staff(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->assertTrue($this->policy->createByStaff($employee, $business));
    }

    public function test_staff_of_another_business_cannot_create_by_staff(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create();

        $this->assertFalse($this->policy->createByStaff($employee, $business));
    }

    public function test_only_customer_role_can_create_by_customer(): void
    {
        $customer = User::factory()->customer()->create();
        $employee = User::factory()->employee()->create();

        $this->assertTrue($this->policy->createByCustomer($customer));
        $this->assertFalse($this->policy->createByCustomer($employee));
    }

    public function test_customer_can_cancel_within_window_staff_can_cancel_anytime(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $bookingSoon = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'starts_at' => CarbonImmutable::now('UTC')->addHour(),
        ]);

        $this->assertFalse($this->policy->cancel($customer, $bookingSoon));
        $this->assertTrue($this->policy->cancel($staff, $bookingSoon));
    }

    public function test_only_staff_can_confirm_complete_or_mark_no_show(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['business_id' => $business->id]);

        $this->assertTrue($this->policy->confirm($staff, $booking));
        $this->assertFalse($this->policy->confirm($customer, $booking));
        $this->assertTrue($this->policy->complete($staff, $booking));
        $this->assertFalse($this->policy->complete($customer, $booking));
        $this->assertTrue($this->policy->markNoShow($staff, $booking));
        $this->assertFalse($this->policy->markNoShow($customer, $booking));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=BookingPolicyTest`
Expected: FAIL — `App\Policies\BookingPolicy` not found.

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;

class BookingPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return in_array($user->role, Role::businessStaff(), true) && $user->business_id === $business->id;
    }

    public function view(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->id
            || ($user->business_id !== null && $user->business_id === $booking->business_id);
    }

    public function createByStaff(User $user, Business $business): bool
    {
        return in_array($user->role, Role::businessStaff(), true) && $user->business_id === $business->id;
    }

    public function createByCustomer(User $user): bool
    {
        return $user->role === Role::Customer;
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->cancelOrReschedule($user, $booking);
    }

    public function reschedule(User $user, Booking $booking): bool
    {
        return $this->cancelOrReschedule($user, $booking);
    }

    public function confirm(User $user, Booking $booking): bool
    {
        return $this->isStaffOfBooking($user, $booking);
    }

    public function complete(User $user, Booking $booking): bool
    {
        return $this->isStaffOfBooking($user, $booking);
    }

    public function markNoShow(User $user, Booking $booking): bool
    {
        return $this->isStaffOfBooking($user, $booking);
    }

    private function cancelOrReschedule(User $user, Booking $booking): bool
    {
        if ($this->isStaffOfBooking($user, $booking)) {
            return true;
        }

        if ($booking->customer_id !== $user->id) {
            return false;
        }

        $business = $booking->business;
        $cutoff = $booking->starts_at->subHours($business->cancellation_hours);

        return CarbonImmutable::now($business->timezone)->lessThanOrEqualTo($cutoff);
    }

    private function isStaffOfBooking(User $user, Booking $booking): bool
    {
        return in_array($user->role, Role::businessStaff(), true) && $user->business_id === $booking->business_id;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=BookingPolicyTest`
Expected: PASS (10 tests)

- [ ] **Step 5: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Policies/BookingPolicy.php tests/Unit/Policies/BookingPolicyTest.php
git commit -m "feat: add BookingPolicy"
```

---

### Task 8: Dashboard backend — Form Requests, `BookingController`, routes

**Files:**
- Create: `app/Http/Requests/Dashboard/BookingRequest.php`
- Create: `app/Http/Requests/Dashboard/RescheduleBookingRequest.php`
- Create: `app/Http/Controllers/Dashboard/BookingController.php`
- Modify: `routes/dashboard.php`
- Test: `tests/Feature/Dashboard/BookingsTest.php`

**Interfaces:**
- Consumes: `App\Actions\Bookings\{CreateBooking,ConfirmBooking,CancelBooking,CompleteBooking,MarkNoShow,RescheduleBooking}` (Tasks 3-6), `App\Policies\BookingPolicy` (Task 7), `App\Models\Business::current()`.
- Produces: named routes `dashboard.bookings.{index,create,store,show,confirm,cancel,complete,noShow,reschedule}`. Consumed by Task 9 (UI) via these route names/URLs.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingsTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_employee_creates_a_manual_booking_for_an_existing_customer(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create(['email' => 'cliente@example.com']);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $response = $this->actingAs($staff)->post('/dashboard/bookings', [
            'customer_email' => 'cliente@example.com',
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0)->toIso8601String(),
            'notes' => 'Cliente pidió turno por teléfono',
        ]);

        $response->assertRedirect('/dashboard/bookings');
        $this->assertDatabaseHas('bookings', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'source' => 'admin',
            'notes' => 'Cliente pidió turno por teléfono',
        ]);
    }

    public function test_employee_of_another_business_cannot_view_or_act_on_a_booking(): void
    {
        $outsider = User::factory()->employee()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        $this->actingAs($outsider)->get("/dashboard/bookings/{$booking->id}")->assertForbidden();
        $this->actingAs($outsider)->post("/dashboard/bookings/{$booking->id}/confirm")->assertForbidden();
    }

    public function test_staff_confirms_cancels_completes_and_marks_no_show(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $pending = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$pending->id}/confirm")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $pending->id, 'status' => 'confirmed']);

        $confirmed = Booking::factory()->confirmed()->create(['business_id' => $business->id]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$confirmed->id}/complete")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $confirmed->id, 'status' => 'completed']);

        $forNoShow = Booking::factory()->confirmed()->create(['business_id' => $business->id]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$forNoShow->id}/no-show")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $forNoShow->id, 'status' => 'no_show']);

        $toCancel = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$toCancel->id}/cancel")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $toCancel->id, 'status' => 'cancelled']);
    }

    public function test_index_lists_only_bookings_of_the_current_business(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        Booking::factory()->count(2)->create(['business_id' => $business->id]);
        Booking::factory()->create();

        $this->actingAs($staff)->get('/dashboard/bookings')
            ->assertInertia(fn ($page) => $page->component('Dashboard/Bookings/Index')->has('bookings', 2));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsTest`
Expected: FAIL — routes/controller don't exist.

- [ ] **Step 3: Write the Form Requests**

```php
<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && in_array($this->user()->role, Role::businessStaff(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_email' => [
                'required',
                'email',
                Rule::exists('users', 'email')->where(fn ($query) => $query->where('role', Role::Customer->value)),
            ],
            'employee_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_email.exists' => 'No existe un cliente registrado con ese email.',
        ];
    }
}
```

```php
<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && in_array($this->user()->role, Role::businessStaff(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CompleteBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\MarkNoShow;
use App\Actions\Bookings\RescheduleBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\BookingRequest;
use App\Http\Requests\Dashboard\RescheduleBookingRequest;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', [Booking::class, Business::current()]);

        return Inertia::render('Dashboard/Bookings/Index', [
            'bookings' => Booking::with(['customer:id,name,email', 'employee:id,name', 'service:id,name'])
                ->orderByDesc('starts_at')
                ->get(),
        ]);
    }

    public function create(AvailabilityService $availabilityService, Request $request): Response
    {
        $this->authorize('createByStaff', [Booking::class, Business::current()]);

        return Inertia::render('Dashboard/Bookings/Form', [
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes']),
            'employees' => User::where('business_id', Business::current()->id)->where('role', 'employee')->orderBy('name')->get(['id', 'name']),
            'slots' => $this->slotsFor($availabilityService, $request),
        ]);
    }

    public function store(BookingRequest $request, CreateBooking $action): RedirectResponse
    {
        $customer = User::where('email', $request->validated('customer_email'))->firstOrFail();

        $action->handle(Business::current(), [
            'customer_id' => $customer->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'admin',
            'notes' => $request->validated('notes'),
        ], $request->user());

        return redirect()->route('dashboard.bookings.index');
    }

    public function show(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        return Inertia::render('Dashboard/Bookings/Show', [
            'booking' => $booking->load(['customer:id,name,email', 'employee:id,name', 'service:id,name', 'statusHistories.changedBy:id,name']),
        ]);
    }

    public function confirm(Booking $booking, ConfirmBooking $action): RedirectResponse
    {
        $this->authorize('confirm', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function cancel(Booking $booking, CancelBooking $action): RedirectResponse
    {
        $this->authorize('cancel', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function complete(Booking $booking, CompleteBooking $action): RedirectResponse
    {
        $this->authorize('complete', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function noShow(Booking $booking, MarkNoShow $action): RedirectResponse
    {
        $this->authorize('markNoShow', $booking);
        $action->handle($booking, request()->user());

        return back();
    }

    public function reschedule(RescheduleBookingRequest $request, Booking $booking, RescheduleBooking $action): RedirectResponse
    {
        $this->authorize('reschedule', $booking);
        $action->handle($booking, $request->validated(), $request->user());

        return back();
    }

    private function slotsFor(AvailabilityService $availabilityService, Request $request): array
    {
        $employeeId = $request->query('employee_id');
        $serviceId = $request->query('service_id');
        $date = $request->query('date');

        if (! $employeeId || ! $serviceId || ! $date) {
            return [];
        }

        $employee = User::where('business_id', Business::current()->id)->find($employeeId);
        $service = Service::find($serviceId);

        if (! $employee || ! $service) {
            return [];
        }

        return $availabilityService->getAvailableSlots(
            Business::current(),
            $service,
            $employee,
            CarbonImmutable::parse($date, Business::current()->timezone),
        );
    }
}
```

- [ ] **Step 5: Register routes**

In `routes/dashboard.php`, add the import `use App\Http\Controllers\Dashboard\BookingController;` and, inside the existing `Route::prefix('dashboard')->name('dashboard.')->group(...)` block, add:

```php
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
        Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('bookings/{booking}/complete', [BookingController::class, 'complete'])->name('bookings.complete');
        Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.noShow');
        Route::put('bookings/{booking}/reschedule', [BookingController::class, 'reschedule'])->name('bookings.reschedule');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Dashboard/BookingRequest.php app/Http/Requests/Dashboard/RescheduleBookingRequest.php app/Http/Controllers/Dashboard/BookingController.php routes/dashboard.php tests/Feature/Dashboard/BookingsTest.php
git commit -m "feat: add dashboard bookings backend (requests, controller, routes)"
```

---

### Task 9: Dashboard UI — `Bookings/{Index,Form,Show}.jsx`

**Files:**
- Create: `resources/js/Pages/Dashboard/Bookings/Index.jsx`
- Create: `resources/js/Pages/Dashboard/Bookings/Form.jsx`
- Create: `resources/js/Pages/Dashboard/Bookings/Show.jsx`
- Modify: `resources/js/Components/DashboardLayout.jsx`
- Modify: `tests/Feature/Dashboard/BookingsTest.php`

**Interfaces:**
- Consumes: props shaped by `Dashboard\BookingController` (Task 8): `Index` gets `bookings` (array with `customer{name,email}`, `employee{name}`, `service{name}`, `starts_at`, `status`); `Form` gets `services`, `employees`, `slots` (array of `{starts_at, ends_at}`); `Show` gets `booking` (with `statusHistories`).

- [ ] **Step 1: Add a feature-test assertion for the `create` page**

Task 8's `BookingsTest` never exercises the `GET /dashboard/bookings/create` route. Add this assertion — it passes immediately (the controller from Task 8 already renders `Dashboard/Bookings/Form`), but locks in the contract the JSX below must satisfy: Inertia only checks the component *name* the server sent, never that the `.jsx` file exists, so building the actual page (Steps 3-6) is what makes that component name real. Add to `tests/Feature/Dashboard/BookingsTest.php`:

```php
    public function test_create_page_renders_the_booking_form(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->actingAs($staff)->get('/dashboard/bookings/create')
            ->assertInertia(fn ($page) => $page->component('Dashboard/Bookings/Form'));
    }
```

Run: `docker compose exec laravel.test php artisan test --filter=BookingsTest`
Expected: PASS immediately (11 tests) — this is not a red/green cycle, since the component-name contract was already satisfied by Task 8's controller. The real gap this task closes is that `resources/js/Pages/Dashboard/Bookings/*.jsx` don't exist yet, which only shows up when the app is opened in a browser (Step 7) or built with `pnpm build`.

- [ ] **Step 2: Add "Reservas" to the dashboard nav**

In `resources/js/Components/DashboardLayout.jsx`, add a link after "Servicios":

```jsx
                <Link href="/dashboard/bookings" className="hover:text-gray-900">Reservas</Link>
```

- [ ] **Step 3: Write `Index.jsx`**

```jsx
import { Link, router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Index({ bookings }) {
    function act(booking, action) {
        router.post(`/dashboard/bookings/${booking.id}/${action}`);
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Reservas</h1>
                    <Link href="/dashboard/bookings/create" className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">
                        Nueva reserva
                    </Link>
                </div>
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Cliente</th>
                            <th className="py-2">Empleado</th>
                            <th className="py-2">Servicio</th>
                            <th className="py-2">Horario</th>
                            <th className="py-2">Estado</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {bookings.map((booking) => (
                            <tr key={booking.id} className="border-b">
                                <td className="py-2">{booking.customer?.name}</td>
                                <td className="py-2">{booking.employee?.name}</td>
                                <td className="py-2">{booking.service?.name}</td>
                                <td className="py-2">{new Date(booking.starts_at).toLocaleString()}</td>
                                <td className="py-2">{STATUS_LABELS[booking.status] ?? booking.status}</td>
                                <td className="py-2 text-right">
                                    <Link href={`/dashboard/bookings/${booking.id}`} className="mr-4 underline">Ver</Link>
                                    {booking.status === 'pending' && (
                                        <button onClick={() => act(booking, 'confirm')} className="mr-4 underline">Confirmar</button>
                                    )}
                                    {booking.status === 'confirmed' && (
                                        <>
                                            <button onClick={() => act(booking, 'complete')} className="mr-4 underline">Completar</button>
                                            <button onClick={() => act(booking, 'no-show')} className="mr-4 underline">Ausencia</button>
                                        </>
                                    )}
                                    {['pending', 'confirmed'].includes(booking.status) && (
                                        <button onClick={() => act(booking, 'cancel')} className="text-red-600 underline">Cancelar</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </DashboardLayout>
    );
}
```

- [ ] **Step 4: Write `Form.jsx`**

```jsx
import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Form({ services, employees, slots }) {
    const { data, setData, post, processing, errors } = useForm({
        customer_email: '',
        service_id: '',
        employee_id: '',
        date: '',
        starts_at: '',
        notes: '',
    });

    useEffect(() => {
        if (data.service_id && data.employee_id && data.date) {
            router.reload({
                data: { service_id: data.service_id, employee_id: data.employee_id, date: data.date },
                only: ['slots'],
            });
        }
    }, [data.service_id, data.employee_id, data.date]);

    function submit(e) {
        e.preventDefault();
        post('/dashboard/bookings');
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-lg p-8">
                <h1 className="mb-6 text-2xl font-bold">Nueva reserva</h1>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Email del cliente</label>
                        <input
                            type="email"
                            value={data.customer_email}
                            onChange={(e) => setData('customer_email', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.customer_email} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Servicio</label>
                        <select
                            value={data.service_id}
                            onChange={(e) => setData('service_id', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {services.map((service) => (
                                <option key={service.id} value={service.id}>{service.name}</option>
                            ))}
                        </select>
                        <InputError message={errors.service_id} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Empleado</label>
                        <select
                            value={data.employee_id}
                            onChange={(e) => setData('employee_id', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {employees.map((employee) => (
                                <option key={employee.id} value={employee.id}>{employee.name}</option>
                            ))}
                        </select>
                        <InputError message={errors.employee_id} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Fecha</label>
                        <input
                            type="date"
                            value={data.date}
                            onChange={(e) => setData('date', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Horario</label>
                        <select
                            value={data.starts_at}
                            onChange={(e) => setData('starts_at', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {slots.map((slot) => (
                                <option key={slot.starts_at} value={slot.starts_at}>
                                    {new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.starts_at} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Notas internas (opcional)</label>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.notes} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Guardar
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
```

- [ ] **Step 5: Write `Show.jsx`**

```jsx
import DashboardLayout from '../../../Components/DashboardLayout';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Show({ booking }) {
    return (
        <DashboardLayout>
            <div className="mx-auto max-w-2xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Reserva #{booking.id}</h1>
                <dl className="mb-8 space-y-2 text-sm">
                    <div><dt className="inline font-medium">Cliente: </dt><dd className="inline">{booking.customer?.name} ({booking.customer?.email})</dd></div>
                    <div><dt className="inline font-medium">Empleado: </dt><dd className="inline">{booking.employee?.name}</dd></div>
                    <div><dt className="inline font-medium">Servicio: </dt><dd className="inline">{booking.service?.name}</dd></div>
                    <div><dt className="inline font-medium">Horario: </dt><dd className="inline">{new Date(booking.starts_at).toLocaleString()} – {new Date(booking.ends_at).toLocaleString()}</dd></div>
                    <div><dt className="inline font-medium">Estado: </dt><dd className="inline">{STATUS_LABELS[booking.status] ?? booking.status}</dd></div>
                    {booking.notes && <div><dt className="inline font-medium">Notas: </dt><dd className="inline">{booking.notes}</dd></div>}
                </dl>
                <h2 className="mb-2 text-lg font-semibold">Historial</h2>
                <ul className="space-y-1 text-sm text-gray-600">
                    {booking.status_histories?.map((entry) => (
                        <li key={entry.id}>
                            {new Date(entry.created_at).toLocaleString()} — {STATUS_LABELS[entry.from_status] ?? 'nueva'} → {STATUS_LABELS[entry.to_status] ?? entry.to_status} ({entry.changed_by?.name ?? 'sistema'})
                            {entry.notes && ` — ${entry.notes}`}
                        </li>
                    ))}
                </ul>
            </div>
        </DashboardLayout>
    );
}
```

- [ ] **Step 6: Build assets and verify in the browser**

Run: `docker compose exec laravel.test pnpm build` (or ensure `pnpm dev` is running per `CLAUDE.md`'s `public/hot` note). Log in as an owner/employee seeded by `DemoSeeder` (Fase 3), visit `/dashboard/bookings`, create a booking end-to-end (pick service → employee → date → slot → submit), confirm it appears, then confirm/cancel/complete it from the Index page and check `/dashboard/bookings/{id}` shows the history timeline.

- [ ] **Step 7: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Dashboard/Bookings resources/js/Components/DashboardLayout.jsx tests/Feature/Dashboard/BookingsTest.php
git commit -m "feat: add dashboard bookings UI (list, create, detail)"
```

---

### Task 10: Public backend — `BindPublicBusiness` middleware, `Public\BusinessController`, `Public\BookingController`, routes

**Files:**
- Create: `app/Http/Middleware/BindPublicBusiness.php`
- Create: `app/Http/Requests/Public/BookingRequest.php`
- Create: `app/Http/Controllers/Public/BusinessController.php`
- Create: `app/Http/Controllers/Public/BookingController.php`
- Create: `routes/public.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Public/BusinessBookingTest.php`

**Interfaces:**
- Consumes: `App\Actions\Bookings\CreateBooking` (Task 3), `App\Policies\BookingPolicy::createByCustomer()` (Task 7), `App\Services\AvailabilityService`.
- Produces: `App\Http\Middleware\BindPublicBusiness` (binds `Business::class` from the route's `business` slug param, order-independent of `SubstituteBindings`). Named routes `public.business.show`, `public.business.booking.create`, `public.business.booking.store`. Consumed by Task 11 (UI).

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Public;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessBookingTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_shows_the_public_business_page_by_slug(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        Service::factory()->for($business)->create(['is_active' => true]);

        $this->get('/negocios/barberia-juan')
            ->assertInertia(fn ($page) => $page->component('Public/Business/Show')->has('services', 1));
    }

    public function test_guest_is_redirected_to_login_when_trying_to_book(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);

        $this->post('/negocios/barberia-juan/reservar', [])->assertRedirect('/login');
    }

    public function test_customer_creates_a_booking_through_the_public_flow(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan', 'timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0, 'is_active' => true]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $response = $this->actingAs($customer)->post('/negocios/barberia-juan/reservar', [
            'service_id' => $service->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->setTime(9, 0)->toIso8601String(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('bookings', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'source' => 'web',
        ]);
    }

    public function test_staff_user_cannot_book_through_the_public_customer_flow(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        $staff = User::factory()->employee()->create();

        $this->actingAs($staff)->post('/negocios/barberia-juan/reservar', [])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=BusinessBookingTest`
Expected: FAIL — routes don't exist.

- [ ] **Step 3: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindPublicBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $business = $route->parameter('business');

        if (! $business instanceof Business) {
            $business = Business::where('slug', $business)->firstOrFail();
            $route->setParameter('business', $business);
        }

        app()->instance(Business::class, $business);

        return $next($request);
    }
}
```

- [ ] **Step 4: Write the Form Request**

```php
<?php

namespace App\Http\Requests\Public;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === Role::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
        ];
    }
}
```

- [ ] **Step 5: Write the controllers**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function show(Business $business): Response
    {
        return Inertia::render('Public/Business/Show', [
            'business' => $business->only(['id', 'name', 'slug']),
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name', 'description', 'duration_minutes', 'price']),
        ]);
    }
}
```

```php
<?php

namespace App\Http\Controllers\Public;

use App\Actions\Bookings\CreateBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BookingRequest;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function create(Business $business, AvailabilityService $availabilityService, Request $request): Response
    {
        $this->authorize('createByCustomer', Booking::class);

        return Inertia::render('Public/Business/Book', [
            'business' => $business->only(['id', 'name', 'slug']),
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes']),
            'employees' => $this->employeesFor($request),
            'slots' => $this->slotsFor($business, $availabilityService, $request),
        ]);
    }

    public function store(BookingRequest $request, Business $business, CreateBooking $action): RedirectResponse
    {
        $this->authorize('createByCustomer', Booking::class);

        $action->handle($business, [
            'customer_id' => $request->user()->id,
            'employee_id' => $request->validated('employee_id'),
            'service_id' => $request->validated('service_id'),
            'starts_at' => $request->validated('starts_at'),
            'source' => 'web',
            'notes' => null,
        ], $request->user());

        return redirect()->route('public.bookings.mine.index');
    }

    private function employeesFor(Request $request): array
    {
        $serviceId = $request->query('service_id');

        if (! $serviceId) {
            return [];
        }

        $service = Service::find($serviceId);

        return $service ? $service->employees()->get(['users.id', 'users.name'])->all() : [];
    }

    private function slotsFor(Business $business, AvailabilityService $availabilityService, Request $request): array
    {
        $employeeId = $request->query('employee_id');
        $serviceId = $request->query('service_id');
        $date = $request->query('date');

        if (! $employeeId || ! $serviceId || ! $date) {
            return [];
        }

        $employee = User::where('business_id', $business->id)->find($employeeId);
        $service = Service::find($serviceId);

        if (! $employee || ! $service) {
            return [];
        }

        return $availabilityService->getAvailableSlots($business, $service, $employee, CarbonImmutable::parse($date, $business->timezone));
    }
}
```

- [ ] **Step 6: Write `routes/public.php` and register it**

```php
<?php

use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\BusinessController;
use App\Http\Middleware\BindPublicBusiness;
use Illuminate\Support\Facades\Route;

Route::prefix('negocios/{business}')->middleware(BindPublicBusiness::class)->name('public.business.')->group(function () {
    Route::get('/', [BusinessController::class, 'show'])->name('show');
    Route::get('/reservar', [BookingController::class, 'create'])->middleware('auth')->name('booking.create');
    Route::post('/reservar', [BookingController::class, 'store'])->middleware('auth')->name('booking.store');
});
```

In `routes/web.php`, add `require __DIR__.'/public.php';` after the existing `require __DIR__.'/invitations.php';` line.

Note: `test_guest_is_redirected_to_login_when_trying_to_book` posts to a route guarded by `auth` middleware but not yet `BindPublicBusiness`-order-sensitive — Laravel's `auth` middleware redirect happens before the controller runs regardless of `BindPublicBusiness`, so no ordering conflict.

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=BusinessBookingTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Middleware/BindPublicBusiness.php app/Http/Requests/Public/BookingRequest.php app/Http/Controllers/Public routes/public.php routes/web.php tests/Feature/Public/BusinessBookingTest.php
git commit -m "feat: add public business page and self-service booking backend"
```

---

### Task 11: Public UI — `Business/{Show,Book}.jsx`

**Files:**
- Create: `resources/js/Pages/Public/Business/Show.jsx`
- Create: `resources/js/Pages/Public/Business/Book.jsx`

**Interfaces:**
- Consumes: props from `Public\BusinessController@show` (`business`, `services`) and `Public\BookingController@create` (`business`, `services`, `employees`, `slots`), per Task 10.

- [ ] **Step 1: Write `Show.jsx`**

```jsx
import { Link } from '@inertiajs/react';

export default function Show({ business, services }) {
    return (
        <div className="min-h-screen bg-gray-50 p-8">
            <h1 className="mb-6 text-2xl font-bold">{business.name}</h1>
            <ul className="space-y-4">
                {services.map((service) => (
                    <li key={service.id} className="rounded-md border bg-white p-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="font-semibold">{service.name}</p>
                                <p className="text-sm text-gray-500">{service.description}</p>
                                <p className="text-sm text-gray-500">{service.duration_minutes} min — ${service.price}</p>
                            </div>
                            <Link
                                href={`/negocios/${business.slug}/reservar?service_id=${service.id}`}
                                className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                            >
                                Reservar
                            </Link>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
```

- [ ] **Step 2: Write `Book.jsx`**

```jsx
import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '../../../Components/InputError';

export default function Book({ business, services, employees, slots }) {
    const { data, setData, post, processing, errors } = useForm({
        service_id: new URLSearchParams(window.location.search).get('service_id') ?? '',
        employee_id: '',
        date: '',
        starts_at: '',
    });

    useEffect(() => {
        if (data.service_id) {
            router.reload({ data: { service_id: data.service_id }, only: ['employees'] });
        }
    }, [data.service_id]);

    useEffect(() => {
        if (data.service_id && data.employee_id && data.date) {
            router.reload({
                data: { service_id: data.service_id, employee_id: data.employee_id, date: data.date },
                only: ['slots'],
            });
        }
    }, [data.service_id, data.employee_id, data.date]);

    function submit(e) {
        e.preventDefault();
        post(`/negocios/${business.slug}/reservar`);
    }

    return (
        <div className="min-h-screen bg-gray-50 p-8">
            <div className="mx-auto max-w-lg">
                <h1 className="mb-6 text-2xl font-bold">Reservar en {business.name}</h1>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Servicio</label>
                        <select
                            value={data.service_id}
                            onChange={(e) => setData('service_id', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {services.map((service) => (
                                <option key={service.id} value={service.id}>{service.name}</option>
                            ))}
                        </select>
                        <InputError message={errors.service_id} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Empleado</label>
                        <select
                            value={data.employee_id}
                            onChange={(e) => setData('employee_id', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {employees.map((employee) => (
                                <option key={employee.id} value={employee.id}>{employee.name}</option>
                            ))}
                        </select>
                        <InputError message={errors.employee_id} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Fecha</label>
                        <input
                            type="date"
                            value={data.date}
                            onChange={(e) => setData('date', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Horario</label>
                        <select
                            value={data.starts_at}
                            onChange={(e) => setData('starts_at', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {slots.map((slot) => (
                                <option key={slot.starts_at} value={slot.starts_at}>
                                    {new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.starts_at} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Confirmar reserva
                    </button>
                </form>
            </div>
        </div>
    );
}
```

- [ ] **Step 3: Build assets and verify in the browser**

Run: `docker compose exec laravel.test pnpm build`. As a logged-in customer (register one via `/register` with account type customer, or use a seeded one), visit `/negocios/{slug de un negocio demo}`, click "Reservar", pick service → employee → date → slot, submit, and confirm a booking appears (check `php artisan tinker` or the dashboard Index from Task 9).

- [ ] **Step 4: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Public/Business
git commit -m "feat: add public business page and self-service booking UI"
```

---

### Task 12: `Public\MyBookingsController` + `Public/MyBookings/Index.jsx`

**Files:**
- Create: `app/Http/Controllers/Public/MyBookingsController.php`
- Create: `resources/js/Pages/Public/MyBookings/Index.jsx`
- Modify: `routes/public.php`
- Test: `tests/Feature/Public/MyBookingsTest.php`

**Interfaces:**
- Consumes: `App\Actions\Bookings\{CancelBooking,RescheduleBooking}` (Tasks 4-5), `App\Policies\BookingPolicy` (Task 7), `App\Models\Scopes\BusinessScope`.
- Produces: named routes `public.bookings.mine.{index,cancel,reschedule}`. `Booking::withoutGlobalScope(BusinessScope::class)` pattern used here — this is the only place in the codebase that bypasses tenant scoping, and it's safe because the query is filtered by `customer_id` instead and every mutation is still gated by `BookingPolicy`.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Public;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyBookingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_only_their_own_bookings_across_businesses(): void
    {
        $customer = User::factory()->customer()->create();
        Booking::factory()->count(2)->create(['customer_id' => $customer->id]);
        Booking::factory()->create();

        $this->actingAs($customer)->get('/mis-reservas')
            ->assertInertia(fn ($page) => $page->component('Public/MyBookings/Index')->has('bookings', 2));
    }

    public function test_customer_cancels_their_own_booking(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(3)->addMinutes(30),
        ]);

        $this->actingAs($customer)->post("/mis-reservas/{$booking->id}/cancel")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_customer_cannot_cancel_someone_elses_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $this->actingAs($customer)->post("/mis-reservas/{$booking->id}/cancel")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=MyBookingsTest`
Expected: FAIL — route/controller don't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RescheduleBookingRequest;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MyBookingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Public/MyBookings/Index', [
            'bookings' => Booking::withoutGlobalScope(BusinessScope::class)
                ->where('customer_id', request()->user()->id)
                ->with(['business:id,name,cancellation_hours', 'employee:id,name', 'service:id,name'])
                ->orderByDesc('starts_at')
                ->get(),
        ]);
    }

    public function cancel(int $booking, CancelBooking $action): RedirectResponse
    {
        $bookingModel = Booking::withoutGlobalScope(BusinessScope::class)->findOrFail($booking);
        $this->authorize('cancel', $bookingModel);

        $action->handle($bookingModel, request()->user());

        return redirect()->route('public.bookings.mine.index');
    }

    public function reschedule(RescheduleBookingRequest $request, int $booking, RescheduleBooking $action): RedirectResponse
    {
        $bookingModel = Booking::withoutGlobalScope(BusinessScope::class)->findOrFail($booking);
        $this->authorize('reschedule', $bookingModel);

        $action->handle($bookingModel, $request->validated(), request()->user());

        return redirect()->route('public.bookings.mine.index');
    }
}
```

- [ ] **Step 4: Write the missing Form Request**

```php
<?php

namespace App\Http\Requests\Public;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === Role::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
        ];
    }
}
```

- [ ] **Step 5: Register routes**

Append to `routes/public.php`:

```php
Route::middleware('auth')->prefix('mis-reservas')->name('public.bookings.mine.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Public\MyBookingsController::class, 'index'])->name('index');
    Route::post('/{booking}/cancel', [\App\Http\Controllers\Public\MyBookingsController::class, 'cancel'])->name('cancel');
    Route::put('/{booking}/reschedule', [\App\Http\Controllers\Public\MyBookingsController::class, 'reschedule'])->name('reschedule');
});
```

Add `use App\Http\Controllers\Public\MyBookingsController;` to the top imports and replace the two fully-qualified references above with the short class name, matching the file's existing style.

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=MyBookingsTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Write `Index.jsx`**

```jsx
import { router } from '@inertiajs/react';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Index({ bookings }) {
    function cancel(booking) {
        if (confirm('¿Cancelar esta reserva?')) {
            router.post(`/mis-reservas/${booking.id}/cancel`);
        }
    }

    return (
        <div className="min-h-screen bg-gray-50 p-8">
            <h1 className="mb-6 text-2xl font-bold">Mis reservas</h1>
            <ul className="space-y-4">
                {bookings.map((booking) => {
                    const cutoff = new Date(booking.starts_at);
                    cutoff.setHours(cutoff.getHours() - (booking.business?.cancellation_hours ?? 0));
                    const canCancel = ['pending', 'confirmed'].includes(booking.status) && new Date() <= cutoff;

                    return (
                        <li key={booking.id} className="rounded-md border bg-white p-4">
                            <p className="font-semibold">{booking.business?.name} — {booking.service?.name}</p>
                            <p className="text-sm text-gray-500">{booking.employee?.name} · {new Date(booking.starts_at).toLocaleString()}</p>
                            <p className="text-sm text-gray-500">{STATUS_LABELS[booking.status] ?? booking.status}</p>
                            {canCancel && (
                                <button onClick={() => cancel(booking)} className="mt-2 text-sm text-red-600 underline">
                                    Cancelar
                                </button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
```

- [ ] **Step 8: Build assets and verify in the browser**

Run: `docker compose exec laravel.test pnpm build`. As a customer with at least one booking, visit `/mis-reservas`, confirm it lists correctly and that "Cancelar" respects `cancellation_hours` (hidden/absent when inside the window).

- [ ] **Step 9: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Public/MyBookingsController.php app/Http/Requests/Public/RescheduleBookingRequest.php resources/js/Pages/Public/MyBookings routes/public.php tests/Feature/Public/MyBookingsTest.php
git commit -m "feat: add customer's own bookings list, cancel and reschedule"
```

---

### Task 13: Concurrency test — two simultaneous requests, only one confirms

**Files:**
- Test: `tests/Unit/Database/AdvisoryLockTest.php`
- Test: `tests/Feature/Bookings/BookingConcurrencyTest.php`

**Interfaces:**
- Consumes: `App\Actions\Bookings\CreateBooking` (Task 3), two raw `\PDO` connections opened directly (not through Laravel's `DB` facade).

A single PHP process can't run two PHPUnit assertions from genuinely parallel OS processes, so "simultaneity" has to be proven a different way: `pg_advisory_xact_lock` semantics are *exactly* "block until the other session's transaction ends," and Postgres exposes a non-blocking probe, `pg_try_advisory_xact_lock`, that returns immediately with true/false instead of blocking. Two independent raw `\PDO` connections (real, separate Postgres sessions — not Eloquent, and deliberately not going through `RefreshDatabase`'s connection, whose whole-test wrapping transaction would turn any `DB::` calls into savepoints instead of real transaction boundaries, breaking lock release semantics) let a single test deterministically prove: session B cannot acquire the lock while session A holds it, and can immediately after A commits. That *is* the mechanism `CreateBooking`/`RescheduleBooking` rely on to serialize two concurrent requests — proving it in isolation is more rigorous than a flaky timing-based race, and doesn't depend on `Booking`/`Business` fixtures at all.

- [ ] **Step 1: Write the lock-mechanics test (no `RefreshDatabase` — needs no fixtures)**

```php
<?php

namespace Tests\Unit\Database;

use PDO;
use Tests\TestCase;

class AdvisoryLockTest extends TestCase
{
    private function rawConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password']);
    }

    public function test_a_second_session_cannot_acquire_the_lock_while_the_first_holds_it_and_can_once_it_is_released(): void
    {
        $sessionA = $this->rawConnection();
        $sessionB = $this->rawConnection();

        $sessionA->beginTransaction();
        $sessionA->exec("select pg_advisory_xact_lock(hashtext('booking-employee-42'))");

        $sessionB->beginTransaction();
        $acquiredWhileHeld = $sessionB->query("select pg_try_advisory_xact_lock(hashtext('booking-employee-42')) as acquired")->fetchColumn();
        $sessionB->commit();

        $this->assertSame('f', $acquiredWhileHeld);

        $sessionA->commit();

        $sessionB->beginTransaction();
        $acquiredAfterRelease = $sessionB->query("select pg_try_advisory_xact_lock(hashtext('booking-employee-42')) as acquired")->fetchColumn();
        $sessionB->commit();

        $this->assertSame('t', $acquiredAfterRelease);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `docker compose exec laravel.test php artisan test --filter=AdvisoryLockTest`
Expected: PASS. If either assertion fails, the lock mechanism `CreateBooking`/`RescheduleBooking` depend on does not behave as designed — stop and investigate before trusting any other test in this fase; don't patch this test to make it pass, the bug would be in the locking approach itself.

- [ ] **Step 3: Write the integration-level test (proves `CreateBooking` actually uses the lock correctly end to end)**

```php
<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CreateBooking;
use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_second_request_for_the_same_slot_is_rejected_once_the_first_has_claimed_it(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customerA = User::factory()->customer()->create();
        $customerB = User::factory()->customer()->create();
        $slot = $this->nextMonday()->setTime(9, 0);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $payload = fn (User $customer) => [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ];

        $first = app(CreateBooking::class)->handle($business, $payload($customerA), $customerA);
        $this->assertNotNull($first->id);

        $this->expectException(ValidationException::class);
        app(CreateBooking::class)->handle($business, $payload($customerB), $customerB);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `docker compose exec laravel.test php artisan test --filter=BookingConcurrencyTest`
Expected: PASS (1 test) — `CreateBooking` (Task 3) already exists, so this passes immediately; it's kept as a regression guard on the integration path, distinct from the lock-mechanics proof in Step 1.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Database/AdvisoryLockTest.php tests/Feature/Bookings/BookingConcurrencyTest.php
git commit -m "test: prove the advisory lock serializes concurrent booking requests"
```

---

### Task 14: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS, all tests green (Fase 4's `AvailabilityServiceTest` plus every test added in Tasks 1-13 of this plan).

- [ ] **Step 2: Run Pint**

Run: `docker compose exec laravel.test vendor/bin/pint --test`
Expected: no style violations. If Pint reformats anything, commit the fix:

```bash
docker compose exec laravel.test vendor/bin/pint
git add -A
git commit -m "style: pint"
```

- [ ] **Step 3: Verify against a real fresh database**

Run: `docker compose exec laravel.test php artisan migrate:fresh --seed`
Expected: no errors — the two new migrations (`booking_status_histories`, and no bookings-table change) run cleanly alongside Fase 0-4's schema.

- [ ] **Step 4: Manual browser verification of both UI surfaces end-to-end**

Per this project's own testing standard (`CLAUDE.md` / working conventions): automated tests verify correctness, not the felt experience of the feature. With the stack up (`docker compose up -d`, `pnpm build` or `pnpm dev` per the `public/hot` note in `CLAUDE.md`):

1. As staff (owner/admin/employee from `DemoSeeder`): create a manual booking via `/dashboard/bookings/create`, confirm/cancel/complete/mark-no-show it from `/dashboard/bookings`, and check the timeline on its `/dashboard/bookings/{id}` page.
2. As a customer: visit `/negocios/{slug}`, book a service end-to-end, then visit `/mis-reservas` and cancel/attempt-to-reschedule it, confirming the `cancellation_hours` cutoff is respected.

Note any UI issue found and fix before considering Fase 5 done — this step is required, not optional, for UI-touching work per the project's standing instructions.
