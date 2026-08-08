# Fase 4 — Motor de disponibilidad — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `App\Services\AvailabilityService::getAvailableSlots()`, a pure calculation service that returns free booking slots for a given business/service/employee/date, combining weekly schedule, breaks, time off, existing bookings (with buffer) and business timezone — unit-tested, no HTTP.

**Architecture:** A `bookings` table + `Booking` model are added as a prerequisite (the engine must exclude existing reservations), scoped like every other tenant table via `BelongsToBusiness`. `AvailabilityService` is a single-method service class with small private helpers (`subtractInterval`, candidate generation, busy-span overlap check) built incrementally, one capability per task, each covered by unit tests before moving to the next.

**Tech Stack:** Laravel 13 (PHP 8.3), PostgreSQL, PHPUnit (`php artisan test`), Carbon/CarbonImmutable, Pint.

## Global Constraints

- Every query on a tenant table must be scoped by `business_id` (project rule, `CLAUDE.md`). `AvailabilityService` binds `Business::class` into the container at the top of `getAvailableSlots()` so the existing `BelongsToBusiness` global scope applies regardless of caller context (HTTP request or direct unit-test call).
- All datetime comparisons against stored columns (`time_offs`, `bookings`) MUST convert local business-timezone `CarbonImmutable` instances to UTC (`->utc()`) before use in query `where()` calls — `datetime` casts store/read in UTC (`config('app.timezone') === 'UTC'`), and Carbon's `format()` renders whatever timezone the instance currently carries, not a conversion.
- Follow existing conventions exactly: enums live in `App\Enums` (see `App\Enums\DayOfWeek`, `App\Enums\Role`), migrations use `foreignId(...)->constrained()->cascadeOnDelete()`, models use the `#[Fillable([...])]` attribute + `BelongsToBusiness` trait where tenant-scoped.
- No Actions, Policies, Controllers, or routes for bookings in this phase — that is Fase 5. `AvailabilityService` and `Booking` (model/migration/factory) are the only new production code.
- `vendor/bin/pint --test` and `php artisan test` must pass at the end of every task (run via `docker compose exec laravel.test ...`, per `CLAUDE.md`).

---

### Task 1: `BookingStatus` enum

**Files:**
- Create: `app/Enums/BookingStatus.php`
- Test: `tests/Unit/Enums/BookingStatusTest.php`

**Interfaces:**
- Produces: `App\Enums\BookingStatus` backed enum with cases `Pending`, `Confirmed`, `Cancelled`, `Completed`, `NoShow`, string values `pending`, `confirmed`, `cancelled`, `completed`, `no_show`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingStatus;
use Tests\TestCase;

class BookingStatusTest extends TestCase
{
    public function test_has_expected_cases_and_values(): void
    {
        $this->assertSame('pending', BookingStatus::Pending->value);
        $this->assertSame('confirmed', BookingStatus::Confirmed->value);
        $this->assertSame('cancelled', BookingStatus::Cancelled->value);
        $this->assertSame('completed', BookingStatus::Completed->value);
        $this->assertSame('no_show', BookingStatus::NoShow->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=BookingStatusTest`
Expected: FAIL (class `App\Enums\BookingStatus` not found)

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=BookingStatusTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/BookingStatus.php tests/Unit/Enums/BookingStatusTest.php
git commit -m "feat: add BookingStatus enum"
```

---

### Task 2: `bookings` table, `Booking` model, `BookingFactory`

**Files:**
- Create: `database/migrations/2026_08_08_000001_create_bookings_table.php`
- Create: `app/Models/Booking.php`
- Create: `database/factories/BookingFactory.php`
- Test: `tests/Feature/Tenancy/BookingsSchemaTest.php`

**Interfaces:**
- Consumes: `App\Enums\BookingStatus` (Task 1), `App\Models\Business`, `App\Models\User`, `App\Models\Service`, `App\Models\Concerns\BelongsToBusiness` (all pre-existing).
- Produces: `App\Models\Booking` with fillable `['customer_id', 'employee_id', 'service_id', 'starts_at', 'ends_at', 'status', 'price', 'deposit_amount', 'notes', 'source', 'cancelled_at']`, casts `starts_at`/`ends_at` → `datetime`, `status` → `BookingStatus`; relations `business()`, `customer()`, `employee()`, `service()`. `Database\Factories\BookingFactory` with states `confirmed()`, `cancelled()`, `completed()`, `noShow()`.

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('bookings'));
        $this->assertTrue(Schema::hasColumns('bookings', [
            'id', 'business_id', 'customer_id', 'employee_id', 'service_id',
            'starts_at', 'ends_at', 'status', 'price', 'deposit_amount',
            'notes', 'source', 'cancelled_at', 'created_at', 'updated_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsSchemaTest`
Expected: FAIL (table `bookings` does not exist)

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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status');
            $table->decimal('price', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('source');
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index(['employee_id', 'starts_at', 'ends_at']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'employee_id', 'service_id', 'starts_at', 'ends_at', 'status', 'price', 'deposit_amount', 'notes', 'source', 'cancelled_at'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => BookingStatus::class,
            'price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'customer_id' => User::factory()->customer(),
            'employee_id' => User::factory()->employee(),
            'service_id' => Service::factory(),
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(10, 30),
            'status' => BookingStatus::Pending,
            'price' => 50,
            'deposit_amount' => null,
            'notes' => null,
            'source' => 'web',
            'cancelled_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::Completed]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::NoShow]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsSchemaTest`
Expected: PASS

- [ ] **Step 7: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_08_000001_create_bookings_table.php app/Models/Booking.php database/factories/BookingFactory.php tests/Feature/Tenancy/BookingsSchemaTest.php
git commit -m "feat: add bookings table, model and factory"
```

---

### Task 3: `AvailabilityService` — schedule lookup + basic slot generation

**Files:**
- Create: `app/Services/AvailabilityService.php`
- Test: `tests/Unit/Services/AvailabilityServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\Business`, `App\Models\Service`, `App\Models\User`, `App\Models\Schedule` (all pre-existing), `App\Enums\DayOfWeek::from(int): DayOfWeek`.
- Produces: `App\Services\AvailabilityService::getAvailableSlots(Business $business, Service $service, User $employee, CarbonImmutable $date): array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>` — the public entry point every later task extends.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Services;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AvailabilityService;
    }

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_returns_empty_array_when_no_active_schedule_for_that_day(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $this->nextMonday());

        $this->assertSame([], $slots);
    }

    public function test_returns_slots_every_duration_minutes_across_the_working_window(): void
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

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(2, $slots);
        $this->assertSame('09:00', $slots[0]['starts_at']->format('H:i'));
        $this->assertSame('09:30', $slots[0]['ends_at']->format('H:i'));
        $this->assertSame('09:30', $slots[1]['starts_at']->format('H:i'));
        $this->assertSame('10:00', $slots[1]['ends_at']->format('H:i'));
    }

    public function test_inactive_schedule_yields_no_slots(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => false,
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $this->nextMonday());

        $this->assertSame([], $slots);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: FAIL (class `App\Services\AvailabilityService` not found)

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

class AvailabilityService
{
    /**
     * @return array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function getAvailableSlots(Business $business, Service $service, User $employee, CarbonImmutable $date): array
    {
        app()->instance(Business::class, $business);

        $timezone = $business->timezone;
        $localDate = $date->setTimezone($timezone)->startOfDay();
        $dayOfWeek = DayOfWeek::from($localDate->dayOfWeek);

        $schedule = Schedule::query()
            ->where('employee_id', $employee->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->first();

        if (! $schedule) {
            return [];
        }

        $windowStart = $localDate->setTimeFromTimeString($schedule->start_time);
        $windowEnd = $localDate->setTimeFromTimeString($schedule->end_time);

        $candidates = $this->generateCandidates([[$windowStart, $windowEnd]], $service->duration_minutes);

        $slots = [];
        foreach ($candidates as $start) {
            $slots[] = [
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($service->duration_minutes),
            ];
        }

        return $slots;
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return array<int, CarbonImmutable>
     */
    private function generateCandidates(array $intervals, int $durationMinutes): array
    {
        $candidates = [];

        foreach ($intervals as [$start, $end]) {
            $cursor = $start;
            while ($cursor->addMinutes($durationMinutes)->lte($end)) {
                $candidates[] = $cursor;
                $cursor = $cursor->addMinutes($durationMinutes);
            }
        }

        return $candidates;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AvailabilityService.php tests/Unit/Services/AvailabilityServiceTest.php
git commit -m "feat: add AvailabilityService with basic schedule-based slot generation"
```

---

### Task 4: Exclude `schedule_breaks`

**Files:**
- Modify: `app/Services/AvailabilityService.php`
- Modify: `tests/Unit/Services/AvailabilityServiceTest.php`

**Interfaces:**
- Consumes: `Schedule::breaks(): HasMany` (pre-existing relation to `App\Models\ScheduleBreak`, columns `start_time`/`end_time`).
- Produces: new private `AvailabilityService::subtractInterval(array $intervals, CarbonImmutable $blockStart, CarbonImmutable $blockEnd): array` — reused by Task 5 and Task 6.

- [ ] **Step 1: Write the failing test**

Add to `AvailabilityServiceTest`:

```php
    public function test_excludes_slots_overlapping_a_schedule_break(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        $schedule = Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);
        $schedule->breaks()->create(['start_time' => '10:00', 'end_time' => '10:30']);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $this->nextMonday());

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00', '09:30', '10:30'], $starts);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: FAIL (`test_excludes_slots_overlapping_a_schedule_break` — break is not subtracted, `10:00` still present)

- [ ] **Step 3: Update the service**

Replace the body of `getAvailableSlots()` (from `$windowStart = ...` down to the `generateCandidates` call) and add the new private method:

```php
        $windowStart = $localDate->setTimeFromTimeString($schedule->start_time);
        $windowEnd = $localDate->setTimeFromTimeString($schedule->end_time);

        $freeIntervals = [[$windowStart, $windowEnd]];

        foreach ($schedule->breaks as $break) {
            $freeIntervals = $this->subtractInterval(
                $freeIntervals,
                $localDate->setTimeFromTimeString($break->start_time),
                $localDate->setTimeFromTimeString($break->end_time),
            );
        }

        $candidates = $this->generateCandidates($freeIntervals, $service->duration_minutes);
```

```php
    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function subtractInterval(array $intervals, CarbonImmutable $blockStart, CarbonImmutable $blockEnd): array
    {
        $result = [];

        foreach ($intervals as [$start, $end]) {
            if ($blockEnd->lte($start) || $blockStart->gte($end)) {
                $result[] = [$start, $end];

                continue;
            }

            if ($blockStart->gt($start)) {
                $result[] = [$start, $blockStart];
            }

            if ($blockEnd->lt($end)) {
                $result[] = [$blockEnd, $end];
            }
        }

        return $result;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AvailabilityService.php tests/Unit/Services/AvailabilityServiceTest.php
git commit -m "feat: exclude schedule breaks from available slots"
```

---

### Task 5: Exclude `time_offs`

**Files:**
- Modify: `app/Services/AvailabilityService.php`
- Modify: `tests/Unit/Services/AvailabilityServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\TimeOff` (pre-existing, columns `employee_id`, `starts_at`, `ends_at`), `AvailabilityService::subtractInterval()` (Task 4).
- Produces: no new public interface; `getAvailableSlots()` now also excludes time off.

- [ ] **Step 1: Write the failing tests**

Add to `AvailabilityServiceTest` (add `use App\Models\TimeOff;` to the imports):

```php
    public function test_excludes_slots_during_a_full_day_time_off(): void
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

        TimeOff::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->subDay(),
            'ends_at' => $date->addDay(),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertSame([], $slots);
    }

    public function test_excludes_slots_during_a_partial_day_time_off(): void
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
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        TimeOff::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00', '10:30'], $starts);
    }
```

Confirm `database/factories/TimeOffFactory.php` exists with a usable `definition()` (it was added in Fase 3); if any of its default fields conflict with the explicit overrides above, the explicit values win via `create([...])`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: FAIL on both new tests (time off not yet subtracted)

- [ ] **Step 3: Update the service**

Insert after the breaks loop, before `$candidates = ...`:

```php
        $timeOffs = TimeOff::query()
            ->where('employee_id', $employee->id)
            ->where('starts_at', '<', $windowEnd->utc())
            ->where('ends_at', '>', $windowStart->utc())
            ->get();

        foreach ($timeOffs as $timeOff) {
            $freeIntervals = $this->subtractInterval(
                $freeIntervals,
                $timeOff->starts_at->setTimezone($timezone),
                $timeOff->ends_at->setTimezone($timezone),
            );
        }

        $candidates = $this->generateCandidates($freeIntervals, $service->duration_minutes);
```

Add `use App\Models\TimeOff;` to the file's imports.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AvailabilityService.php tests/Unit/Services/AvailabilityServiceTest.php
git commit -m "feat: exclude time offs from available slots"
```

---

### Task 6: Exclude existing bookings, with buffer

**Files:**
- Modify: `app/Services/AvailabilityService.php`
- Modify: `tests/Unit/Services/AvailabilityServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\Booking` (Task 2), `App\Enums\BookingStatus` (Task 1), `App\Models\Booking::service(): BelongsTo`.
- Produces: no new public interface; `getAvailableSlots()` now also excludes busy spans from `pending`/`confirmed`/`completed` bookings, each extended by that booking's own service `buffer_minutes`, and applies the *requested* service's `buffer_minutes` to each candidate before checking overlap.

- [ ] **Step 1: Write the failing tests**

Add to `AvailabilityServiceTest` (add `use App\Enums\BookingStatus;` and `use App\Models\Booking;` to the imports):

```php
    public function test_existing_booking_blocks_its_slot_and_the_next_one_via_buffer(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 10]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(10, 0),
            'ends_at' => $date->setTime(10, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:00'], $starts);
    }

    public function test_cancelled_and_no_show_bookings_do_not_block_slots(): void
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

        Booking::factory()->cancelled()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);
        Booking::factory()->noShow()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 30),
            'ends_at' => $date->setTime(10, 0),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(2, $slots);
    }

    public function test_zero_buffer_allows_back_to_back_slots(): void
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

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['09:30'], $starts);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: FAIL on the three new tests (bookings not yet excluded)

- [ ] **Step 3: Update the service**

Replace the tail of `getAvailableSlots()` (from `$candidates = $this->generateCandidates(...)` to the end of the method):

```php
        $candidates = $this->generateCandidates($freeIntervals, $service->duration_minutes);

        $busySpans = Booking::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::Completed])
            ->where('starts_at', '<', $windowEnd->utc())
            ->where('ends_at', '>', $windowStart->utc())
            ->with('service')
            ->get()
            ->map(fn (Booking $booking) => [
                $booking->starts_at->setTimezone($timezone),
                $booking->ends_at->setTimezone($timezone)->addMinutes($booking->service->buffer_minutes),
            ])
            ->all();

        $slots = [];
        foreach ($candidates as $start) {
            $end = $start->addMinutes($service->duration_minutes);
            $occupiedEnd = $end->addMinutes($service->buffer_minutes);

            if ($this->overlapsAny($start, $occupiedEnd, $busySpans)) {
                continue;
            }

            $slots[] = ['starts_at' => $start, 'ends_at' => $end];
        }

        return $slots;
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $spans
     */
    private function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $spans): bool
    {
        foreach ($spans as [$spanStart, $spanEnd]) {
            if ($start->lt($spanEnd) && $spanStart->lt($end)) {
                return true;
            }
        }

        return false;
    }
```

Add `use App\Enums\BookingStatus;` and `use App\Models\Booking;` to the file's imports.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: PASS (9 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AvailabilityService.php tests/Unit/Services/AvailabilityServiceTest.php
git commit -m "feat: exclude existing bookings and apply service buffer"
```

---

### Task 7: Exclude past slots for "today"

**Files:**
- Modify: `app/Services/AvailabilityService.php`
- Modify: `tests/Unit/Services/AvailabilityServiceTest.php`

**Interfaces:**
- Consumes: `Carbon\CarbonImmutable::setTestNow()` (test only), `App\Services\AvailabilityService` (Task 6).
- Produces: no new public interface; `getAvailableSlots()` now filters out candidates starting before "now" only when `$date` is today in the business's timezone.

- [ ] **Step 1: Write the failing tests**

Add to `AvailabilityServiceTest` (add `protected function tearDown(): void { CarbonImmutable::setTestNow(); parent::tearDown(); }` to reset the clock after each test):

```php
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_excludes_past_slots_when_date_is_today(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::from($today->dayOfWeek),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        CarbonImmutable::setTestNow($today->setTime(9, 45));

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $today);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);
        $this->assertSame(['10:00', '10:30'], $starts);
    }

    public function test_does_not_filter_by_current_time_for_a_future_date(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
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

        CarbonImmutable::setTestNow($date->subDay());

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(2, $slots);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: FAIL on `test_excludes_past_slots_when_date_is_today` (09:00 and 09:30 still returned)

- [ ] **Step 3: Update the service**

In `getAvailableSlots()`, right after computing `$busySpans` and before the `$slots = [];` loop, add:

```php
        $now = CarbonImmutable::now($timezone);
        $isToday = $localDate->isSameDay($now);
```

Then change the loop condition to also skip past candidates:

```php
        $slots = [];
        foreach ($candidates as $start) {
            if ($isToday && $start->lt($now)) {
                continue;
            }

            $end = $start->addMinutes($service->duration_minutes);
            $occupiedEnd = $end->addMinutes($service->buffer_minutes);

            if ($this->overlapsAny($start, $occupiedEnd, $busySpans)) {
                continue;
            }

            $slots[] = ['starts_at' => $start, 'ends_at' => $end];
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: PASS (11 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AvailabilityService.php tests/Unit/Services/AvailabilityServiceTest.php
git commit -m "feat: exclude already-past slots when querying today's availability"
```

---

### Task 8: Timezone correctness (business tz ≠ UTC, crossing UTC midnight)

**Files:**
- Modify: `tests/Unit/Services/AvailabilityServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\AvailabilityService` (Task 7, no further changes expected — this task is a regression test for the `.utc()` conversions already in place since Task 5/6).

- [ ] **Step 1: Write the test**

Add to `AvailabilityServiceTest`. Note the explicit `->utc()` on the `TimeOff` timestamps below: Eloquent's `datetime` cast writes whatever timezone the given `Carbon` instance carries, it does not convert — so a `CarbonImmutable` built in `Asia/Tokyo` must be converted before `create()`, the same reasoning as the Global Constraints note about query bindings, just on the write side instead of the read side.

```php
    public function test_computes_correct_local_slots_when_business_timezone_crosses_utc_midnight(): void
    {
        // Asia/Tokyo is UTC+9: a 00:00-02:00 local schedule falls on the
        // *previous* UTC calendar day (15:00-17:00 UTC).
        $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = CarbonImmutable::parse('next monday', 'Asia/Tokyo')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '00:00',
            'end_time' => '02:00',
            'is_active' => true,
        ]);

        TimeOff::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->setTime(0, 30)->utc(),
            'ends_at' => $date->setTime(1, 0)->utc(),
        ]);

        $slots = $this->service->getAvailableSlots($business, $service, $employee, $date);

        $this->assertCount(3, $slots);
        $this->assertSame('00:00', $slots[0]['starts_at']->format('H:i'));
        $this->assertSame('Asia/Tokyo', $slots[0]['starts_at']->getTimezone()->getName());
        $this->assertSame('01:00', $slots[1]['starts_at']->format('H:i'));
        $this->assertSame('01:30', $slots[2]['starts_at']->format('H:i'));
    }
```

- [ ] **Step 2: Run test to verify it passes without further code changes**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityServiceTest`
Expected: PASS (12 tests). If it fails, the bug is almost certainly a missing `->utc()` on a query boundary in `getAvailableSlots()` (see Global Constraints) — fix there, not in the test.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Services/AvailabilityServiceTest.php
git commit -m "test: cover business timezones that cross UTC midnight"
```

---

### Task 9: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS, all tests green (including all `AvailabilityServiceTest` and `BookingsSchemaTest` cases from this plan).

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
Expected: no errors (the `bookings` migration runs cleanly alongside the existing Fase 0-3 schema; `DemoSeeder` from Fase 3 does not touch `bookings`, so nothing new to inspect manually here beyond "no migration error").
