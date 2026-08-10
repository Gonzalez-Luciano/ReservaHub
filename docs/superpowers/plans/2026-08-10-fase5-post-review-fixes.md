# Fase 5 — Post-review fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the 6 "Important" findings from the final whole-branch review of `docs/superpowers/plans/2026-08-09-fase5-reservas.md` (commit range `92920d0..1ea64ce`): missing reschedule UI on both surfaces, no server-side validation that a booked employee actually performs the booked service, booking wizards not resetting dependent fields on change, no navigation into the customer's own booking list, an untested/misleadingly-named concurrency test, and an unhardened dashboard `slotsFor()`. Also folds in the three low-cost Minor findings worth fixing alongside (advisory lock key namespacing, missing FK index, unconstrained numeric route param).

**Architecture:** Reschedule gets a small new JSON endpoint per surface (`GET .../reschedule-slots?date=`) that both controllers already have every ingredient for (`AvailabilityService`, the booking's own `service`/`employee`, `excludeBookingId`) — it's just never been exposed to the frontend. The UI is an inline expandable form under each booking row/card (no new modal component, no new dependency — matches the codebase's plain-Tailwind, no-component-library convention already used everywhere in Fase 5). `CreateBooking` gains a same-shape guard to its existing cross-business check. The concurrency test is renamed and documented honestly rather than pretending a single PHP process can prove OS-level concurrency — the namespaced advisory-lock key it always claimed to test now actually matches what production uses.

**Tech Stack:** Laravel 13 (PHP 8.5), PostgreSQL, Inertia 3 + React (JSX, Tailwind, no TypeScript), PHPUnit, Pint.

## Global Constraints

- Spec of record: the final review's findings, quoted per-task below. This is a fix plan, not a fresh feature — every task closes one specific, already-identified gap, nothing more.
- Follow every convention already established across Fase 5: `#[Fillable([...])]` attribute on models, `app()->instance(Business::class, ...)` bound before any tenant-scoped query in code paths without `business`/`BindPublicBusiness` middleware, `ValidationException::withMessages([...])` in Spanish for domain errors, PHPUnit `TestCase` classes (not Pest), Tailwind-only JSX with no new shared components beyond what's specified below.
- New JSON endpoints (`reschedule-slots`) return `response()->json([...])`, not `Inertia::render(...)` — they're fetched client-side via `fetch()`, not Inertia visits, since they populate an inline form's options without navigating away from the current page.
- Every `Run:` command goes through `docker compose exec laravel.test` per `CLAUDE.md`. `pnpm build` after every JSX change.

---

### Task 1: `CreateBooking` — validate the employee actually performs the service, and both are active

**Files:**
- Modify: `app/Actions/Bookings/CreateBooking.php`
- Modify: `tests/Feature/Bookings/CreateBookingTest.php`

**Finding closed:** Important #2 — "Nothing server-side validates that the employee performs the service, or that the service/employee are active." `Service::findOrFail`/`User::...->findOrFail` have no `is_active` check and no `employee_service` pivot check, so `POST /negocios/{slug}/reservar` accepts any employee/service pair as long as the employee's weekly schedule is open that day.

**Interfaces:**
- Consumes: `App\Models\Service::employees(): BelongsToMany` (pivot `employee_service`, already exists since Fase 3).
- Produces: no new public interface — `CreateBooking::handle()` now additionally rejects inactive service/employee and an employee not assigned to the service, via the same `ValidationException` shape it already uses.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Bookings/CreateBookingTest.php` (reuse the existing `setUpBusinessWithSchedule()` helper already in that file):

```php
    public function test_rejects_an_inactive_service(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $service->update(['is_active' => false]);
        $slot = $this->nextMonday()->setTime(9, 0);

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

    public function test_rejects_an_inactive_employee(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        $employee->update(['is_active' => false]);
        $slot = $this->nextMonday()->setTime(9, 0);

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

    public function test_rejects_an_employee_not_assigned_to_the_service(): void
    {
        ['business' => $business, 'employee' => $employee, 'service' => $service, 'customer' => $customer] = $this->setUpBusinessWithSchedule();
        // Note: setUpBusinessWithSchedule() does not attach $employee to $service's pivot,
        // so this is already the failing case — no extra setup needed. Assert it explicitly.
        $this->assertFalse($service->employees()->whereKey($employee->id)->exists());
        $slot = $this->nextMonday()->setTime(9, 0);

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=CreateBookingTest`
Expected: `test_rejects_an_inactive_service` and `test_rejects_an_inactive_employee` PASS already (no exception thrown means... actually FAIL, since nothing currently throws — the booking is created instead of raising `ValidationException`). `test_rejects_an_employee_not_assigned_to_the_service` also FAILS for the same reason. All three FAIL before the fix.

- [ ] **Step 3: Add the guards to `CreateBooking::handle()`**

Find the existing cross-business guard (`if ($service->business_id !== $business->id) { throw ValidationException::withMessages(['service_id' => '...']); }`) and add immediately after it:

```php
        if (! $service->is_active) {
            throw ValidationException::withMessages(['service_id' => 'Este servicio no está disponible.']);
        }

        if (! $employee->is_active) {
            throw ValidationException::withMessages(['employee_id' => 'Este empleado no está disponible.']);
        }

        if (! $service->employees()->whereKey($employee->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Ese empleado no realiza este servicio.']);
        }
```

Place these before the transaction opens (same section as the existing `service_id` cross-business check), not inside the lock — they don't depend on the slot, only on the service/employee themselves.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=CreateBookingTest`
Expected: PASS (existing tests + 3 new ones).

- [ ] **Step 5: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Bookings/CreateBooking.php tests/Feature/Bookings/CreateBookingTest.php
git commit -m "fix: reject inactive service/employee and unassigned employee in CreateBooking"
```

---

### Task 2: Namespace the advisory-lock key; make the concurrency test honest about what it proves

**Files:**
- Modify: `app/Actions/Bookings/CreateBooking.php`
- Modify: `app/Actions/Bookings/RescheduleBooking.php`
- Modify: `tests/Feature/Bookings/BookingConcurrencyTest.php`

**Findings closed:**
- Important #5 — "`BookingConcurrencyTest` does not test concurrency... it would still pass with the `pg_advisory_xact_lock` line deleted." The design's own testing requirement ("dos conexiones DB separadas... ejercita el lock real") is not met by this test; `AdvisoryLockTest` (which does use two real connections) proves Postgres's lock semantics in the abstract, not that `CreateBooking` actually serializes.
- Minor #1 — production locks on `hashtext((string) $employee->id)`, but `AdvisoryLockTest` documents/uses the namespaced form `hashtext('booking-employee-42')`. Divergence between what the test suite treats as "the convention" and what ships.

**Resolution chosen (of the two the review offered):** rename `BookingConcurrencyTest`'s existing method to describe what it actually verifies (sequential rejection after commit — still a real, valuable regression test), add a docblock on the class explaining that OS-level concurrency is proven separately by `AdvisoryLockTest` (lock mechanics) and is *not* re-proven at the `CreateBooking` integration layer because a single PHP process cannot host two genuinely parallel database transactions without spawning a second process — which this fase does not do. This makes the gap visible instead of a misleadingly-named test implying more than it checks. Alongside this, namespace the production lock key so the two "halves" of the concurrency story (unit-level lock mechanics, integration-level rejection) are at least keyed consistently.

- [ ] **Step 1: Namespace the lock key in both Actions**

In `app/Actions/Bookings/CreateBooking.php`, change:

```php
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', [(string) $employee->id]);
```

to:

```php
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['booking-employee-'.$employee->id]);
```

In `app/Actions/Bookings/RescheduleBooking.php`, apply the identical change (same string shape: `'booking-employee-'.$employee->id`) — the two Actions must keep locking on the byte-identical expression for the same employee, or they stop serializing against each other.

- [ ] **Step 2: Run the existing Action tests to confirm the rename doesn't change behavior**

Run: `docker compose exec laravel.test php artisan test --filter=CreateBookingTest`
Run: `docker compose exec laravel.test php artisan test --filter=RescheduleBookingTest`
Expected: PASS, unchanged — the lock's *effect* (serializing per employee) is identical, only the key's text representation changed. `hashtext()` output differs, but nothing in the app compares hash values across runs, so this is purely cosmetic/consistency.

- [ ] **Step 3: Rename and document `BookingConcurrencyTest`**

Replace the full contents of `tests/Feature/Bookings/BookingConcurrencyTest.php`:

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

/**
 * Covers the integration-level half of overlap-safety: a second CreateBooking
 * call for an already-claimed slot is rejected. This does NOT exercise genuine
 * OS-level concurrency — a single PHP process cannot host two truly parallel
 * database transactions without spawning a second process, which this fase
 * does not do. The lock's actual blocking/serialization semantics (session B
 * cannot acquire the advisory lock while session A holds it, and can once A
 * commits) are proven separately, with two real Postgres sessions, by
 * tests/Unit/Database/AdvisoryLockTest.php. Together the two tests cover:
 * "the lock mechanism works" (AdvisoryLockTest) and "CreateBooking uses it
 * correctly to reject a slot someone else already claimed" (this test).
 */
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
        $service->employees()->attach($employee->id);
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

        try {
            app(CreateBooking::class)->handle($business, $payload($customerB), $customerB);
            $this->fail('Expected ValidationException for an already-claimed slot.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('starts_at', $e->errors());
        }

        $this->assertDatabaseCount('bookings', 1);
    }
}
```

(Note: adds `$service->employees()->attach($employee->id)` and `'starts_at' => key assertion + row-count assertion, both closing gaps the earlier reviewer had already flagged as ledger minors for this file — folded in here since the file is being rewritten anyway.)

- [ ] **Step 4: Run the test**

Run: `docker compose exec laravel.test php artisan test --filter=BookingConcurrencyTest`
Expected: PASS (1 test).

- [ ] **Step 5: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Bookings/CreateBooking.php app/Actions/Bookings/RescheduleBooking.php tests/Feature/Bookings/BookingConcurrencyTest.php
git commit -m "fix: namespace advisory lock key consistently; make BookingConcurrencyTest's scope honest"
```

---

### Task 3: Booking wizards reset dependent fields when an earlier selection changes

**Files:**
- Modify: `resources/js/Pages/Dashboard/Bookings/Form.jsx`
- Modify: `resources/js/Pages/Public/Business/Book.jsx`

**Finding closed:** Important #3 — picking service A → employee X → switching to service B leaves `employee_id` (and `starts_at`) holding the stale value, invisibly, because the `<select>` re-renders without that now-invalid option selected but `data.employee_id` isn't cleared. The stale value still gets submitted. Combined with Task 1's new pivot check, this would otherwise become a confusing "ese empleado no realiza este servicio" error on submit instead of the UI just not offering an invalid combination in the first place.

**Interfaces:** No new interfaces — this is a same-file behavioral fix to existing `useForm`/`useEffect` state in both files.

- [ ] **Step 1: Fix `Dashboard/Bookings/Form.jsx`**

Change the service `<select>`'s `onChange` and the employee `<select>`'s `onChange`:

```jsx
                        <select
                            value={data.service_id}
                            onChange={(e) => setData((d) => ({ ...d, service_id: e.target.value, employee_id: '', starts_at: '' }))}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
```

(that's the Servicio `<select>`; keep its surrounding `<div>`/label/`InputError` unchanged)

```jsx
                        <select
                            value={data.employee_id}
                            onChange={(e) => setData((d) => ({ ...d, employee_id: e.target.value, starts_at: '' }))}
                            disabled={loadingSlots}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm disabled:opacity-50"
                        >
```

Wait — the Empleado `<select>` in this file isn't currently gated by `loadingSlots` (only the Horario one is, from the earlier manual-QA fix). Leave the Empleado `<select>` exactly as it is otherwise (no `disabled`/opacity change), just change its `onChange` to also clear `starts_at`:

```jsx
                        <select
                            value={data.employee_id}
                            onChange={(e) => setData((d) => ({ ...d, employee_id: e.target.value, starts_at: '' }))}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
```

Also clear `starts_at` when the date changes (currently `onChange={(e) => setData('date', e.target.value)}`):

```jsx
                        <input
                            type="date"
                            value={data.date}
                            onChange={(e) => setData((d) => ({ ...d, date: e.target.value, starts_at: '' }))}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
```

- [ ] **Step 2: Apply the identical fix to `Public/Business/Book.jsx`**

Same three `onChange` handlers (Servicio clears `employee_id`+`starts_at`; Empleado clears `starts_at`; Fecha clears `starts_at`), same functional pattern as Step 1.

- [ ] **Step 3: Build assets**

Run: `docker compose exec laravel.test pnpm build`
Expected: no errors.

- [ ] **Step 4: Manual smoke check**

In the browser: dashboard "Nueva reserva" — pick service A, pick an employee, then change to service B. Confirm the Empleado `<select>` resets to "Elegir…" (not silently keeping the old selection). Repeat on the public `/negocios/{slug}/reservar` page.

- [ ] **Step 5: Run full suite (no backend changed, but confirm nothing else broke) and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Dashboard/Bookings/Form.jsx resources/js/Pages/Public/Business/Book.jsx
git commit -m "fix: reset dependent employee/slot selection when an earlier field changes"
```

---

### Task 4: Harden the dashboard `slotsFor()` against malformed query params

**Files:**
- Modify: `app/Http/Controllers/Dashboard/BookingController.php`
- Modify: `tests/Feature/Dashboard/BookingsTest.php`

**Finding closed:** Important #6 — `Public\BookingController::slotsFor()` was hardened during the original Task 10's fix round (`is_numeric()` guards, try/catch around `CarbonImmutable::parse()`), but the dashboard's `Dashboard\BookingController::slotsFor()` (written in Task 8, never touched since) never got the same treatment. `GET /dashboard/bookings/create?employee_id=abc&service_id=1&date=x` 500s.

**Interfaces:** No new interfaces — same-shape fix as the already-shipped `Public\BookingController::slotsFor()`, which is the reference implementation to copy from.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Dashboard/BookingsTest.php`:

```php
    public function test_create_page_does_not_500_on_malformed_query_params(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->actingAs($staff)
            ->get('/dashboard/bookings/create?employee_id=abc&service_id=abc&date=not-a-date')
            ->assertOk();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsTest`
Expected: FAIL — 500 (`QueryException` on the bigint cast, or `InvalidFormatException` on the date parse, depending on which guard is hit first).

- [ ] **Step 3: Harden `Dashboard\BookingController::slotsFor()`**

Replace the method body:

```php
    private function slotsFor(AvailabilityService $availabilityService, Request $request): array
    {
        $employeeId = $request->query('employee_id');
        $serviceId = $request->query('service_id');
        $date = $request->query('date');

        if (! $employeeId || ! is_numeric($employeeId) || ! $serviceId || ! is_numeric($serviceId) || ! $date) {
            return [];
        }

        try {
            $parsedDate = CarbonImmutable::parse($date, Business::current()->timezone);
        } catch (\Exception) {
            return [];
        }

        $employee = User::where('business_id', Business::current()->id)->find($employeeId);
        $service = Service::find($serviceId);

        if (! $employee || ! $service) {
            return [];
        }

        return $availabilityService->getAvailableSlots(Business::current(), $service, $employee, $parsedDate);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsTest`
Expected: PASS.

- [ ] **Step 5: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Dashboard/BookingController.php tests/Feature/Dashboard/BookingsTest.php
git commit -m "fix: harden dashboard slotsFor() against malformed query params"
```

---

### Task 5: Reschedule UI — dashboard (staff)

**Files:**
- Modify: `app/Http/Controllers/Dashboard/BookingController.php`
- Modify: `routes/dashboard.php`
- Modify: `resources/js/Pages/Dashboard/Bookings/Index.jsx`
- Modify: `tests/Feature/Dashboard/BookingsTest.php`

**Finding closed:** Important #1 (dashboard half) — the design doc explicitly calls for a "Reprogramar" action on `Dashboard/Bookings/Index.jsx`, reusing a slot-picker; the plan's Task 9 never actually specified it, so it was never built, leaving `RescheduleBooking`/the `PUT .../reschedule` route unreachable from the dashboard.

**Interfaces:**
- Produces: `GET dashboard/bookings/{booking}/reschedule-slots?date=YYYY-MM-DD` → `BookingController::rescheduleSlots()`, returns `{"slots": [{"starts_at": "...", "ends_at": "..."}, ...]}` as JSON (not an Inertia render). Consumed by `Dashboard/Bookings/Index.jsx`'s new inline reschedule form via `fetch()`.

- [ ] **Step 1: Write the failing backend tests**

Add to `tests/Feature/Dashboard/BookingsTest.php`:

```php
    public function test_reschedule_slots_endpoint_returns_available_slots_excluding_the_booking_itself(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->confirmed()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $response = $this->actingAs($staff)
            ->get("/dashboard/bookings/{$booking->id}/reschedule-slots?date={$date->format('Y-m-d')}")
            ->assertOk()
            ->json();

        $starts = array_column($response['slots'], 'starts_at');
        $this->assertCount(2, $response['slots']);
        $this->assertStringContainsString('09:00', $starts[0]);
    }

    public function test_reschedule_slots_endpoint_requires_reschedule_authorization(): void
    {
        $outsider = User::factory()->employee()->create();
        $booking = Booking::factory()->confirmed()->create();

        $this->actingAs($outsider)
            ->get("/dashboard/bookings/{$booking->id}/reschedule-slots?date=2026-08-17")
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsTest`
Expected: FAIL — route doesn't exist (404, not 200/403).

- [ ] **Step 3: Add the controller method**

Add to `app/Http/Controllers/Dashboard/BookingController.php` (needs `use Illuminate\Http\JsonResponse;` added to the imports):

```php
    public function rescheduleSlots(Booking $booking, AvailabilityService $availabilityService, Request $request): JsonResponse
    {
        $this->authorize('reschedule', $booking);

        $date = $request->query('date');

        if (! $date) {
            return response()->json(['slots' => []]);
        }

        try {
            $parsedDate = CarbonImmutable::parse($date, Business::current()->timezone);
        } catch (\Exception) {
            return response()->json(['slots' => []]);
        }

        $slots = $availabilityService->getAvailableSlots(
            Business::current(),
            $booking->service,
            $booking->employee,
            $parsedDate,
            excludeBookingId: $booking->id,
        );

        return response()->json(['slots' => $slots]);
    }
```

- [ ] **Step 4: Register the route**

In `routes/dashboard.php`, add right after the existing `bookings.reschedule` line:

```php
        Route::get('bookings/{booking}/reschedule-slots', [BookingController::class, 'rescheduleSlots'])->name('bookings.reschedule-slots');
```

- [ ] **Step 5: Run backend tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=BookingsTest`
Expected: PASS.

- [ ] **Step 6: Add the inline reschedule form to `Dashboard/Bookings/Index.jsx`**

Replace the full file:

```jsx
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

const CONFIRM_MESSAGES = {
    confirm: '¿Confirmar esta reserva?',
    complete: '¿Marcar esta reserva como completada?',
    'no-show': '¿Marcar esta reserva como ausencia?',
    cancel: '¿Cancelar esta reserva?',
};

export default function Index({ bookings }) {
    const [reschedulingId, setReschedulingId] = useState(null);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleSlots, setRescheduleSlots] = useState([]);
    const [rescheduleStartsAt, setRescheduleStartsAt] = useState('');
    const [loadingSlots, setLoadingSlots] = useState(false);

    function act(booking, action) {
        const message = CONFIRM_MESSAGES[action];
        if (message && !confirm(message)) {
            return;
        }
        router.post(`/dashboard/bookings/${booking.id}/${action}`);
    }

    function startReschedule(booking) {
        setReschedulingId(booking.id);
        setRescheduleDate('');
        setRescheduleSlots([]);
        setRescheduleStartsAt('');
    }

    function cancelReschedule() {
        setReschedulingId(null);
    }

    async function onRescheduleDateChange(booking, date) {
        setRescheduleDate(date);
        setRescheduleStartsAt('');
        setRescheduleSlots([]);
        if (!date) {
            return;
        }
        setLoadingSlots(true);
        try {
            const response = await fetch(`/dashboard/bookings/${booking.id}/reschedule-slots?date=${date}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            setRescheduleSlots(data.slots ?? []);
        } finally {
            setLoadingSlots(false);
        }
    }

    function submitReschedule(booking) {
        if (!rescheduleStartsAt) {
            return;
        }
        router.put(`/dashboard/bookings/${booking.id}/reschedule`, { starts_at: rescheduleStartsAt }, {
            onSuccess: () => setReschedulingId(null),
        });
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
                            <tr key={booking.id} className="border-b align-top">
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
                                        <>
                                            <button onClick={() => startReschedule(booking)} className="mr-4 underline">Reprogramar</button>
                                            <button onClick={() => act(booking, 'cancel')} className="text-red-600 underline">Cancelar</button>
                                        </>
                                    )}
                                    {reschedulingId === booking.id && (
                                        <div className="mt-2 rounded-md border bg-gray-50 p-3 text-left">
                                            <label className="block text-xs font-medium text-gray-700">Nueva fecha</label>
                                            <input
                                                type="date"
                                                value={rescheduleDate}
                                                onChange={(e) => onRescheduleDateChange(booking, e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                            />
                                            <label className="mt-2 block text-xs font-medium text-gray-700">Nuevo horario</label>
                                            <select
                                                value={rescheduleStartsAt}
                                                onChange={(e) => setRescheduleStartsAt(e.target.value)}
                                                disabled={loadingSlots}
                                                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm disabled:opacity-50"
                                            >
                                                <option value="">{loadingSlots ? 'Cargando…' : 'Elegir…'}</option>
                                                {rescheduleSlots.map((slot) => (
                                                    <option key={slot.starts_at} value={slot.starts_at}>
                                                        {new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                    </option>
                                                ))}
                                            </select>
                                            <div className="mt-2 flex gap-3">
                                                <button
                                                    onClick={() => submitReschedule(booking)}
                                                    disabled={!rescheduleStartsAt}
                                                    className="rounded-md bg-gray-900 px-3 py-1 text-xs font-semibold text-white disabled:opacity-50"
                                                >
                                                    Confirmar
                                                </button>
                                                <button onClick={cancelReschedule} className="text-xs underline">Cancelar</button>
                                            </div>
                                        </div>
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

- [ ] **Step 7: Build assets**

Run: `docker compose exec laravel.test pnpm build`
Expected: no errors.

- [ ] **Step 8: Manual smoke check**

In the browser: `/dashboard/bookings`, click "Reprogramar" on a pending/confirmed booking, pick a date, confirm slots load, pick one, click "Confirmar", confirm the row updates with the new time.

- [ ] **Step 9: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Dashboard/BookingController.php routes/dashboard.php resources/js/Pages/Dashboard/Bookings/Index.jsx tests/Feature/Dashboard/BookingsTest.php
git commit -m "feat: add reschedule UI to the dashboard bookings list"
```

---

### Task 6: Reschedule UI — public (customer, "Mis reservas")

**Files:**
- Modify: `app/Http/Controllers/Public/MyBookingsController.php`
- Modify: `routes/public.php`
- Modify: `resources/js/Pages/Public/MyBookings/Index.jsx`
- Modify: `tests/Feature/Public/MyBookingsTest.php`

**Finding closed:** Important #1 (public half) — same gap as Task 5, on the customer-facing surface. The design doc specifically said this page's "Reprogramar" should reuse the slot-picker; here it's the same inline-form pattern as Task 5, not a shared React component (this codebase has none, and introducing one for two call sites is not warranted — YAGNI).

**Interfaces:**
- Produces: `GET mis-reservas/{booking}/reschedule-slots?date=YYYY-MM-DD` → `MyBookingsController::rescheduleSlots()`, same JSON shape as Task 5's endpoint.

- [ ] **Step 1: Write the failing backend tests**

Add to `tests/Feature/Public/MyBookingsTest.php`:

```php
    public function test_reschedule_slots_endpoint_returns_available_slots_excluding_the_booking_itself(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

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

        $response = $this->actingAs($customer)
            ->get("/mis-reservas/{$booking->id}/reschedule-slots?date={$date->format('Y-m-d')}")
            ->assertOk()
            ->json();

        $this->assertCount(2, $response['slots']);
    }

    public function test_reschedule_slots_endpoint_requires_reschedule_authorization(): void
    {
        $otherCustomer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $this->actingAs($otherCustomer)
            ->get("/mis-reservas/{$booking->id}/reschedule-slots?date=2026-08-17")
            ->assertForbidden();
    }
```

(Add `use App\Enums\DayOfWeek;`, `use App\Models\Schedule;`, `use App\Models\Service;`, `use Carbon\CarbonImmutable;` to the file's imports if not already present.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=MyBookingsTest`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Add the controller method**

Add to `app/Http/Controllers/Public/MyBookingsController.php` (needs `use App\Models\Business;`, `use App\Services\AvailabilityService;`, `use Carbon\CarbonImmutable;`, `use Illuminate\Http\JsonResponse;` added to imports):

```php
    public function rescheduleSlots(int $booking, AvailabilityService $availabilityService, Request $request): JsonResponse
    {
        $bookingModel = Booking::withoutGlobalScope(BusinessScope::class)->findOrFail($booking);
        $this->authorize('reschedule', $bookingModel);

        $business = $bookingModel->business;
        app()->instance(Business::class, $business);

        $date = $request->query('date');

        if (! $date) {
            return response()->json(['slots' => []]);
        }

        try {
            $parsedDate = CarbonImmutable::parse($date, $business->timezone);
        } catch (\Exception) {
            return response()->json(['slots' => []]);
        }

        $slots = $availabilityService->getAvailableSlots(
            $business,
            $bookingModel->service,
            $bookingModel->employee,
            $parsedDate,
            excludeBookingId: $bookingModel->id,
        );

        return response()->json(['slots' => $slots]);
    }
```

Also add `use Illuminate\Http\Request;` if not already imported.

- [ ] **Step 4: Register the route**

In `routes/public.php`, add inside the existing `mis-reservas` group, after the `reschedule` line:

```php
    Route::get('/{booking}/reschedule-slots', [MyBookingsController::class, 'rescheduleSlots'])->name('reschedule-slots');
```

- [ ] **Step 5: Run backend tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=MyBookingsTest`
Expected: PASS.

- [ ] **Step 6: Add the inline reschedule form to `Public/MyBookings/Index.jsx`**

Replace the full file:

```jsx
import { router } from '@inertiajs/react';
import { useState } from 'react';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Index({ bookings }) {
    const [reschedulingId, setReschedulingId] = useState(null);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleSlots, setRescheduleSlots] = useState([]);
    const [rescheduleStartsAt, setRescheduleStartsAt] = useState('');
    const [loadingSlots, setLoadingSlots] = useState(false);

    function cancel(booking) {
        if (confirm('¿Cancelar esta reserva?')) {
            router.post(`/mis-reservas/${booking.id}/cancel`);
        }
    }

    function startReschedule(booking) {
        setReschedulingId(booking.id);
        setRescheduleDate('');
        setRescheduleSlots([]);
        setRescheduleStartsAt('');
    }

    async function onRescheduleDateChange(booking, date) {
        setRescheduleDate(date);
        setRescheduleStartsAt('');
        setRescheduleSlots([]);
        if (!date) {
            return;
        }
        setLoadingSlots(true);
        try {
            const response = await fetch(`/mis-reservas/${booking.id}/reschedule-slots?date=${date}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            setRescheduleSlots(data.slots ?? []);
        } finally {
            setLoadingSlots(false);
        }
    }

    function submitReschedule(booking) {
        if (!rescheduleStartsAt) {
            return;
        }
        router.put(`/mis-reservas/${booking.id}/reschedule`, { starts_at: rescheduleStartsAt }, {
            onSuccess: () => setReschedulingId(null),
        });
    }

    return (
        <div className="min-h-screen bg-gray-50 p-8">
            <h1 className="mb-6 text-2xl font-bold">Mis reservas</h1>
            <ul className="space-y-4">
                {bookings.map((booking) => {
                    const cutoff = new Date(booking.starts_at);
                    cutoff.setHours(cutoff.getHours() - (booking.business?.cancellation_hours ?? 0));
                    const canAct = ['pending', 'confirmed'].includes(booking.status) && new Date() <= cutoff;

                    return (
                        <li key={booking.id} className="rounded-md border bg-white p-4">
                            <p className="font-semibold">{booking.business?.name} — {booking.service?.name}</p>
                            <p className="text-sm text-gray-500">{booking.employee?.name} · {new Date(booking.starts_at).toLocaleString()}</p>
                            <p className="text-sm text-gray-500">{STATUS_LABELS[booking.status] ?? booking.status}</p>
                            {canAct && (
                                <div className="mt-2 flex gap-4">
                                    <button onClick={() => startReschedule(booking)} className="text-sm underline">
                                        Reprogramar
                                    </button>
                                    <button onClick={() => cancel(booking)} className="text-sm text-red-600 underline">
                                        Cancelar
                                    </button>
                                </div>
                            )}
                            {reschedulingId === booking.id && (
                                <div className="mt-2 rounded-md border bg-gray-50 p-3">
                                    <label className="block text-xs font-medium text-gray-700">Nueva fecha</label>
                                    <input
                                        type="date"
                                        value={rescheduleDate}
                                        onChange={(e) => onRescheduleDateChange(booking, e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                    />
                                    <label className="mt-2 block text-xs font-medium text-gray-700">Nuevo horario</label>
                                    <select
                                        value={rescheduleStartsAt}
                                        onChange={(e) => setRescheduleStartsAt(e.target.value)}
                                        disabled={loadingSlots}
                                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm disabled:opacity-50"
                                    >
                                        <option value="">{loadingSlots ? 'Cargando…' : 'Elegir…'}</option>
                                        {rescheduleSlots.map((slot) => (
                                            <option key={slot.starts_at} value={slot.starts_at}>
                                                {new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            </option>
                                        ))}
                                    </select>
                                    <div className="mt-2 flex gap-3">
                                        <button
                                            onClick={() => submitReschedule(booking)}
                                            disabled={!rescheduleStartsAt}
                                            className="rounded-md bg-gray-900 px-3 py-1 text-xs font-semibold text-white disabled:opacity-50"
                                        >
                                            Confirmar
                                        </button>
                                        <button onClick={() => setReschedulingId(null)} className="text-xs underline">Cancelar</button>
                                    </div>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
```

- [ ] **Step 7: Build assets**

Run: `docker compose exec laravel.test pnpm build`
Expected: no errors.

- [ ] **Step 8: Manual smoke check**

In the browser: `/mis-reservas`, click "Reprogramar" on a booking well outside the cancellation window, pick a date/slot, confirm, verify the card updates.

- [ ] **Step 9: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Public/MyBookingsController.php routes/public.php resources/js/Pages/Public/MyBookings/Index.jsx tests/Feature/Public/MyBookingsTest.php
git commit -m "feat: add reschedule UI to the customer's own bookings page"
```

---

### Task 7: Public layout with navigation into "Mis reservas"

**Files:**
- Create: `resources/js/Components/PublicLayout.jsx`
- Modify: `resources/js/Pages/Public/Business/Show.jsx`
- Modify: `resources/js/Pages/Public/Business/Book.jsx`
- Modify: `resources/js/Pages/Public/MyBookings/Index.jsx`
- Modify: `resources/js/Pages/Home.jsx`

**Finding closed:** Important #4 — a logged-in customer has no navigation into `/mis-reservas` except the one-shot redirect immediately after booking; the three public pages render bare `<div>`s with no shared header (unlike staff, who have `DashboardLayout`).

**Interfaces:**
- Produces: `PublicLayout` component (`{ children }` prop), same shape/role as the existing `DashboardLayout` but for the public surface. Shows "Mis reservas" + "Salir" only when `usePage().props.auth.user` is present (a guest browsing `/negocios/{slug}` before logging in sees no nav bar changes — no requirement to build a guest-facing header, out of scope per the finding).

- [ ] **Step 1: Create `PublicLayout.jsx`**

```jsx
import { Link, router, usePage } from '@inertiajs/react';

export default function PublicLayout({ children }) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-gray-50">
            {auth?.user && (
                <nav className="flex items-center gap-6 border-b bg-white px-6 py-3 text-sm font-medium text-gray-700">
                    <Link href="/mis-reservas" className="hover:text-gray-900">Mis reservas</Link>
                    <button onClick={() => router.post('/logout')} className="ml-auto hover:text-gray-900">
                        Salir
                    </button>
                </nav>
            )}
            <main>{children}</main>
        </div>
    );
}
```

- [ ] **Step 2: Wrap `Public/Business/Show.jsx`**

Wrap the existing returned JSX in `<PublicLayout>...</PublicLayout>`, removing the outer `min-h-screen bg-gray-50` div's own background/min-height classes (now owned by the layout) but keeping the inner padding/content div as-is. Add `import PublicLayout from '../../../Components/PublicLayout';` to the imports.

- [ ] **Step 3: Wrap `Public/Business/Book.jsx`** — identical treatment to Step 2.

- [ ] **Step 4: Wrap `Public/MyBookings/Index.jsx`** — identical treatment to Step 2. Add the `PublicLayout` import (`'../../../Components/PublicLayout'`).

- [ ] **Step 5: Add a customer link to `Home.jsx`**

```jsx
import { Link, usePage } from '@inertiajs/react';

export default function Home() {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen flex flex-col items-center justify-center gap-4">
            <h1 className="text-3xl font-bold">ReservaHub</h1>
            {auth?.user?.role === 'customer' && (
                <Link href="/mis-reservas" className="text-sm underline">Ver mis reservas</Link>
            )}
        </div>
    );
}
```

- [ ] **Step 6: Build assets**

Run: `docker compose exec laravel.test pnpm build`
Expected: no errors.

- [ ] **Step 7: Manual smoke check**

Log in as a customer, visit `/`, confirm the "Ver mis reservas" link appears and works; visit `/negocios/{slug}` and `/mis-reservas`, confirm the nav bar with "Mis reservas"/"Salir" renders on both; log out, confirm the nav bar disappears from `/negocios/{slug}` (still publicly viewable, just without customer nav).

- [ ] **Step 8: Run full suite and Pint** (no backend touched, but confirm nothing broke)

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Components/PublicLayout.jsx resources/js/Pages/Public/Business/Show.jsx resources/js/Pages/Public/Business/Book.jsx resources/js/Pages/Public/MyBookings/Index.jsx resources/js/Pages/Home.jsx
git commit -m "feat: add public layout with navigation into the customer's own bookings"
```

---

### Task 8: Small hygiene fixes (Minor findings folded in)

**Files:**
- Create: `database/migrations/2026_08_10_000001_add_index_to_booking_status_histories_changed_by.php`
- Modify: `routes/public.php`
- Modify: `app/Http/Controllers/Dashboard/BookingController.php`

**Findings closed:**
- Minor #2 — `booking_status_histories.changed_by` has no index, unlike the repo's own precedent (`2026_08_07_000007_add_missing_foreign_key_indexes.php` for the analogous nullable FK `employee_invitations.invited_by_id`).
- Minor #7 — `/mis-reservas/{booking}/cancel|reschedule` take a raw `int $booking` with no route constraint; a non-numeric segment produces a `TypeError` → 500 instead of a 404.
- Minor #6 — `Dashboard\BookingController::create()` hardcodes the raw string `'employee'` instead of `Role::Employee->value`/the enum, the only such raw string on the branch.

- [ ] **Step 1: Add the missing index migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_status_histories', function (Blueprint $table) {
            $table->index('changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('booking_status_histories', function (Blueprint $table) {
            $table->dropIndex(['changed_by']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `docker compose exec laravel.test php artisan migrate`
Expected: migration runs clean.

- [ ] **Step 3: Constrain the `{booking}` route parameter**

In `routes/public.php`, add `->whereNumber('booking')` to both routes taking that parameter in the `mis-reservas` group (`cancel` and `reschedule` — and `reschedule-slots` if Task 6 already landed):

```php
    Route::post('/{booking}/cancel', [MyBookingsController::class, 'cancel'])->name('cancel')->whereNumber('booking');
    Route::put('/{booking}/reschedule', [MyBookingsController::class, 'reschedule'])->name('reschedule')->whereNumber('booking');
    Route::get('/{booking}/reschedule-slots', [MyBookingsController::class, 'rescheduleSlots'])->name('reschedule-slots')->whereNumber('booking');
```

- [ ] **Step 4: Add a quick regression test**

Add to `tests/Feature/Public/MyBookingsTest.php`:

```php
    public function test_non_numeric_booking_id_returns_not_found(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)->post('/mis-reservas/abc/cancel')->assertNotFound();
    }
```

- [ ] **Step 5: Fix the raw role string in `Dashboard\BookingController::create()`**

Change:

```php
            'employees' => User::where('business_id', Business::current()->id)->where('role', 'employee')->orderBy('name')->get(['id', 'name']),
```

to:

```php
            'employees' => User::where('business_id', Business::current()->id)->where('role', Role::Employee)->orderBy('name')->get(['id', 'name']),
```

Add `use App\Enums\Role;` to the file's imports if not already present.

- [ ] **Step 6: Run full suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_10_000001_add_index_to_booking_status_histories_changed_by.php routes/public.php app/Http/Controllers/Dashboard/BookingController.php tests/Feature/Public/MyBookingsTest.php
git commit -m "fix: add missing FK index, constrain booking route param, use Role enum consistently"
```

---

### Task 9: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS, all tests green (everything from Fase 5's original 14 tasks plus this plan's 8).

- [ ] **Step 2: Run Pint**

Run: `docker compose exec laravel.test vendor/bin/pint --test`
Expected: no style violations.

- [ ] **Step 3: Verify against a real fresh database**

Run: `docker compose exec laravel.test php artisan migrate:fresh --seed`
Expected: no errors, including the new `2026_08_10_000001` migration.

- [ ] **Step 4: `pnpm build`**

Run: `docker compose exec laravel.test pnpm build`
Expected: no errors.

- [ ] **Step 5: Manual browser walkthrough of the two new reschedule flows end-to-end**

Per this project's standing instructions on UI-touching work: as staff, reprogramar a booking from `/dashboard/bookings`; as a customer, reprogramar a booking from `/mis-reservas`. Confirm both update the booking's time and the history timeline (`/dashboard/bookings/{id}`) shows the reprogramación note. Confirm the public layout nav appears/disappears correctly logged in/out.
