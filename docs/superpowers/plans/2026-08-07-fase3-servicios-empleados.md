# Fase 3 — Servicios y empleados Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `services`, employee accounts (via email invitation), employee-service assignment, weekly `schedules` + `schedule_breaks`, and `time_offs` to ReservaHub, with owner/admin-only CRUD (backend + basic Inertia UI) and a demo seeder.

**Architecture:** Laravel 12 + Inertia/React, PHPUnit feature/unit tests, Action-per-use-case under `app/Actions`, Policy-per-model under `app/Policies`. `services`, `schedules`, `time_offs`, and `employee_invitations` are the first real consumers of the `BelongsToBusiness` trait built in Fase 2 — they get their own `business_id` column and rely on `Business::current()` (bound by the existing `EnsureBusinessContext` / `business` middleware) for both the read-time global scope and auto-filling `business_id` on create. `schedule_breaks` has no `business_id` of its own; it's authorized transitively through its parent `schedule`. An employee is a `User` with `role = Employee`, created only when an `employee_invitations` row is accepted (never directly) — `users` has no tenant scope (per Fase 2), so every controller/action that takes an employee `User` as a route parameter must explicitly check `employee->business_id` (reusing `UserPolicy::update`), since route-model-binding on `User` is not tenant-filtered.

**Tech Stack:** Laravel 12, PHP 8.3 backed enums, Inertia 3 + React (JSX, Tailwind utility classes, no component library), PHPUnit (not Pest) — see `tests/Feature/Auth/*Test.php` and `docs/superpowers/plans/2026-08-06-fase2-tenancy.md` for the established style.

## Global Constraints

- Spec of record: `docs/superpowers/specs/2026-08-07-fase3-servicios-empleados-design.md` — every task below implements one of its sections.
- Only owner/admin manage services, employees, schedules, breaks, and time-offs in this fase. No employee self-service UI (see spec's "Fuera de alcance").
- No availability engine, no bookings, no `/api/*` routes — those are Fase 4/5/7. Controllers in this fase return Inertia responses only.
- Follow existing conventions: PHPUnit `TestCase` classes (not Pest), `#[Fillable(...)]` attribute on models (not `$fillable` property), Actions as plain classes with a `handle()` method returning the model, Form Requests with `authorize(): bool { return true; }` (authorization happens via policy in the controller, not the request), Inertia pages under `resources/js/Pages`, routes grouped by concern in `routes/*.php` and required from `routes/web.php`.
- Every new tenant-owned table (`services`, `employee_invitations`, `schedules`, `time_offs`) gets a `business_id` foreign key + index and uses the `App\Models\Concerns\BelongsToBusiness` trait. `schedule_breaks` and `employee_service` do not get their own `business_id` (see spec's Decisiones table).
- Laravel's `exists:table,column` validation rule queries the raw table and is **not** scoped by Eloquent global scopes — never rely on it alone to prove a submitted ID belongs to the current business. Where this matters (employee-service assignment), re-check via a scoped Eloquent query in the Action.
- The invite email is sent synchronously (no `ShouldQueue`) — this project doesn't yet have a queue worker running as part of normal dev flow (`QUEUE_CONNECTION=database` but nothing consumes it until a later fase); keep it simple.
- Every `Run:` command below that invokes `php artisan` or `vendor/bin/pint` must go through the Docker/Sail stack per `CLAUDE.md` — prefix with `docker compose exec laravel.test` (e.g. `docker compose exec laravel.test php artisan test --filter=X`). There is no working native PHP path on this project; a bare `php artisan test` will fail to reach the `pgsql`/`redis`/`mailpit` containers. `git`/`pnpm` commands run on the host as normal (not through Docker). Run `WWWUSER=1000 WWWGROUP=1000 docker compose up -d` once before starting Task 1 if the stack isn't already up.

---

### Task 1: Base `Controller` authorization + `services` CRUD (backend + UI)

**Files:**
- Modify: `app/Http/Controllers/Controller.php`
- Create: `database/migrations/2026_08_07_000001_create_services_table.php`
- Create: `app/Models/Service.php`
- Create: `database/factories/ServiceFactory.php`
- Create: `app/Policies/ServicePolicy.php`
- Create: `app/Http/Requests/Dashboard/ServiceRequest.php`
- Create: `app/Actions/Services/CreateService.php`
- Create: `app/Actions/Services/UpdateService.php`
- Create: `app/Actions/Services/DeleteService.php`
- Create: `app/Http/Controllers/Dashboard/ServiceController.php`
- Modify: `routes/dashboard.php`
- Create: `resources/js/Components/DashboardLayout.jsx`
- Modify: `resources/js/Pages/Dashboard/Index.jsx`
- Create: `resources/js/Pages/Dashboard/Services/Index.jsx`
- Create: `resources/js/Pages/Dashboard/Services/Form.jsx`
- Test: `tests/Feature/Tenancy/ServicesSchemaTest.php`
- Test: `tests/Feature/Dashboard/ServicesTest.php`

**Interfaces:**
- Consumes: `App\Models\Business` (`Business::current()`), `App\Models\Concerns\BelongsToBusiness`, `App\Enums\Role::managers()` (all from Fase 2).
- Produces: `App\Models\Service` (fillable `business_id, name, description, duration_minutes, buffer_minutes, price, deposit_amount, is_active`; casts `duration_minutes`/`buffer_minutes` int, `price`/`deposit_amount` `decimal:2`, `is_active` bool). `Service::employees(): BelongsToMany` (pivot `employee_service`, foreign pivot key `service_id`, related pivot key `employee_id`) — Task 4 relies on this relation existing. `CreateService::handle(array $data): Service`, `UpdateService::handle(Service $service, array $data): Service`, `DeleteService::handle(Service $service): void`. Named routes `dashboard.services.{index,create,store,edit,update,destroy}`.

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServicesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('services'));
        $this->assertTrue(Schema::hasColumns('services', [
            'id', 'business_id', 'name', 'description', 'duration_minutes',
            'buffer_minutes', 'price', 'deposit_amount', 'is_active',
            'created_at', 'updated_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=ServicesSchemaTest`
Expected: FAIL — `services` table doesn't exist yet.

- [ ] **Step 3: Add `AuthorizesRequests` to the base `Controller`**

No controller in the codebase calls `$this->authorize()` yet — this fase is the first to need it.

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
}
```

- [ ] **Step 4: Create the `services` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->decimal('price', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=ServicesSchemaTest`
Expected: PASS

- [ ] **Step 6: Create the `Service` model and factory**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['business_id', 'name', 'description', 'duration_minutes', 'buffer_minutes', 'price', 'deposit_amount', 'is_active'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employee_service', 'service_id', 'employee_id')
            ->withTimestamps();
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->randomElement(['Corte de cabello', 'Coloración', 'Manicura', 'Masaje', 'Depilación']),
            'description' => fake()->sentence(),
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90]),
            'buffer_minutes' => 10,
            'price' => fake()->randomFloat(2, 10, 200),
            'deposit_amount' => null,
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 7: Write the failing feature test for the CRUD + policy**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_service(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post('/dashboard/services', [
            'name' => 'Corte de cabello',
            'description' => 'Corte clásico',
            'duration_minutes' => 30,
            'buffer_minutes' => 5,
            'price' => 25.5,
            'deposit_amount' => null,
            'is_active' => true,
        ]);

        $response->assertRedirect('/dashboard/services');
        $this->assertDatabaseHas('services', [
            'business_id' => $business->id,
            'name' => 'Corte de cabello',
        ]);
    }

    public function test_owner_can_list_and_edit_own_services(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();

        $this->actingAs($owner)->get('/dashboard/services')
            ->assertInertia(fn ($page) => $page->has('services', 1));

        $response = $this->actingAs($owner)->put("/dashboard/services/{$service->id}", [
            'name' => 'Nuevo nombre',
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'buffer_minutes' => $service->buffer_minutes,
            'price' => $service->price,
            'deposit_amount' => null,
            'is_active' => true,
        ]);

        $response->assertRedirect('/dashboard/services');
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Nuevo nombre']);
    }

    public function test_employee_can_view_but_not_manage_services(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();

        $this->actingAs($employee)->get('/dashboard/services')->assertOk();
        $this->actingAs($employee)->post('/dashboard/services', ['name' => 'X'])->assertForbidden();
        $this->actingAs($employee)->put("/dashboard/services/{$service->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($employee)->delete("/dashboard/services/{$service->id}")->assertForbidden();
    }

    public function test_owner_cannot_edit_or_delete_service_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $serviceB = Service::factory()->for($businessB)->create();

        $this->actingAs($ownerA)->get("/dashboard/services/{$serviceB->id}/edit")->assertNotFound();
        $this->actingAs($ownerA)->delete("/dashboard/services/{$serviceB->id}")->assertNotFound();
    }
}
```

- [ ] **Step 8: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=ServicesTest`
Expected: FAIL — no `ServicePolicy`/`ServiceController`/routes yet.

- [ ] **Step 9: Create the policy, form request, and actions**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->business_id !== null;
    }

    public function view(User $user, Service $service): bool
    {
        return $user->business_id === $service->business_id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->business_id === $service->business_id
            && in_array($user->role, Role::managers(), true);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }
}
```

```php
<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'buffer_minutes' => ['required', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
```

```php
<?php

namespace App\Actions\Services;

use App\Models\Service;

class CreateService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Service
    {
        return Service::create($data);
    }
}
```

```php
<?php

namespace App\Actions\Services;

use App\Models\Service;

class UpdateService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Service $service, array $data): Service
    {
        $service->update($data);

        return $service;
    }
}
```

```php
<?php

namespace App\Actions\Services;

use App\Models\Service;

class DeleteService
{
    public function handle(Service $service): void
    {
        $service->delete();
    }
}
```

- [ ] **Step 10: Create the controller**

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Services\CreateService;
use App\Actions\Services\DeleteService;
use App\Actions\Services\UpdateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ServiceRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Service::class);

        return Inertia::render('Dashboard/Services/Index', [
            'services' => Service::orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Service::class);

        return Inertia::render('Dashboard/Services/Form');
    }

    public function store(ServiceRequest $request, CreateService $action): RedirectResponse
    {
        $this->authorize('create', Service::class);

        $action->handle($request->validated());

        return redirect()->route('dashboard.services.index');
    }

    public function edit(Service $service): Response
    {
        $this->authorize('update', $service);

        return Inertia::render('Dashboard/Services/Form', ['service' => $service]);
    }

    public function update(ServiceRequest $request, Service $service, UpdateService $action): RedirectResponse
    {
        $this->authorize('update', $service);

        $action->handle($service, $request->validated());

        return redirect()->route('dashboard.services.index');
    }

    public function destroy(Service $service, DeleteService $action): RedirectResponse
    {
        $this->authorize('delete', $service);

        $action->handle($service);

        return redirect()->route('dashboard.services.index');
    }
}
```

- [ ] **Step 11: Add the routes**

```php
<?php

use App\Http\Controllers\Dashboard\ServiceController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::resource('services', ServiceController::class)->except(['show']);
    });
});
```

- [ ] **Step 12: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=ServicesTest`
Expected: PASS. Note `assertNotFound()` in the cross-business test passes because `Service` uses `BelongsToBusiness`: route-model-binding queries through the global scope, so a service from another business simply doesn't resolve (404) before the policy even runs.

- [ ] **Step 13: Add a shared dashboard nav and wire up the Services UI**

```jsx
import { Link, router } from '@inertiajs/react';

export default function DashboardLayout({ children }) {
    return (
        <div className="min-h-screen bg-gray-50">
            <nav className="flex items-center gap-6 border-b bg-white px-6 py-3 text-sm font-medium text-gray-700">
                <Link href="/dashboard" className="hover:text-gray-900">Panel</Link>
                <Link href="/dashboard/services" className="hover:text-gray-900">Servicios</Link>
                <Link href="/dashboard/employees" className="hover:text-gray-900">Empleados</Link>
                <button onClick={() => router.post('/logout')} className="ml-auto hover:text-gray-900">
                    Salir
                </button>
            </nav>
            <main>{children}</main>
        </div>
    );
}
```

```jsx
import DashboardLayout from '../../Components/DashboardLayout';

export default function Index({ business }) {
    return (
        <DashboardLayout>
            <div className="p-8">
                <h1 className="text-2xl font-bold">Panel de {business.name}</h1>
                <p className="mt-2 text-sm text-gray-600">
                    El dashboard real (reservas de hoy, ingresos, etc.) llega en una fase posterior.
                </p>
            </div>
        </DashboardLayout>
    );
}
```

```jsx
import { Link, router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';

export default function Index({ services }) {
    function destroy(service) {
        if (confirm(`¿Eliminar "${service.name}"?`)) {
            router.delete(`/dashboard/services/${service.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Servicios</h1>
                    <Link
                        href="/dashboard/services/create"
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Nuevo servicio
                    </Link>
                </div>
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Nombre</th>
                            <th className="py-2">Duración</th>
                            <th className="py-2">Precio</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {services.map((service) => (
                            <tr key={service.id} className="border-b">
                                <td className="py-2">{service.name}</td>
                                <td className="py-2">{service.duration_minutes} min</td>
                                <td className="py-2">${service.price}</td>
                                <td className="py-2 text-right">
                                    <Link href={`/dashboard/services/${service.id}/edit`} className="mr-4 underline">
                                        Editar
                                    </Link>
                                    <button onClick={() => destroy(service)} className="text-red-600 underline">
                                        Eliminar
                                    </button>
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

```jsx
import { useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Form({ service }) {
    const isEdit = !!service;
    const { data, setData, post, put, processing, errors } = useForm({
        name: service?.name ?? '',
        description: service?.description ?? '',
        duration_minutes: service?.duration_minutes ?? 30,
        buffer_minutes: service?.buffer_minutes ?? 0,
        price: service?.price ?? '',
        deposit_amount: service?.deposit_amount ?? '',
        is_active: service?.is_active ?? true,
    });

    function submit(e) {
        e.preventDefault();
        if (isEdit) {
            put(`/dashboard/services/${service.id}`);
        } else {
            post('/dashboard/services');
        }
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-lg p-8">
                <h1 className="mb-6 text-2xl font-bold">{isEdit ? 'Editar servicio' : 'Nuevo servicio'}</h1>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Nombre</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Descripción</label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.description} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Duración (minutos)</label>
                        <input
                            type="number"
                            value={data.duration_minutes}
                            onChange={(e) => setData('duration_minutes', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.duration_minutes} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Buffer (minutos)</label>
                        <input
                            type="number"
                            value={data.buffer_minutes}
                            onChange={(e) => setData('buffer_minutes', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.buffer_minutes} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Precio</label>
                        <input
                            type="number"
                            step="0.01"
                            value={data.price}
                            onChange={(e) => setData('price', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.price} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Seña (opcional)</label>
                        <input
                            type="number"
                            step="0.01"
                            value={data.deposit_amount ?? ''}
                            onChange={(e) => setData('deposit_amount', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.deposit_amount} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                        />
                        Activo
                    </label>
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

- [ ] **Step 14: Run the full test suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 15: Commit**

```bash
git add app/Http/Controllers/Controller.php database/migrations/2026_08_07_000001_create_services_table.php \
  app/Models/Service.php database/factories/ServiceFactory.php app/Policies/ServicePolicy.php \
  app/Http/Requests/Dashboard/ServiceRequest.php app/Actions/Services app/Http/Controllers/Dashboard/ServiceController.php \
  routes/dashboard.php resources/js/Components/DashboardLayout.jsx resources/js/Pages/Dashboard/Index.jsx \
  resources/js/Pages/Dashboard/Services tests/Feature/Tenancy/ServicesSchemaTest.php tests/Feature/Dashboard/ServicesTest.php
git commit -m "feat: add services CRUD (owner/admin only)"
```

---

### Task 2: `employee_invitations` + invite/resend/revoke (backend + UI)

**Files:**
- Create: `database/migrations/2026_08_07_000002_create_employee_invitations_table.php`
- Create: `app/Models/EmployeeInvitation.php`
- Create: `database/factories/EmployeeInvitationFactory.php`
- Create: `app/Policies/EmployeeInvitationPolicy.php`
- Create: `app/Http/Requests/Dashboard/InviteEmployeeRequest.php`
- Create: `app/Actions/Employees/InviteEmployee.php`
- Create: `app/Actions/Employees/ResendInvitation.php`
- Create: `app/Actions/Employees/RevokeInvitation.php`
- Create: `app/Notifications/EmployeeInvited.php`
- Create: `app/Http/Controllers/Dashboard/EmployeeController.php`
- Create: `app/Http/Controllers/Dashboard/EmployeeInvitationController.php`
- Modify: `routes/dashboard.php`
- Create: `resources/js/Pages/Dashboard/Employees/Index.jsx`
- Test: `tests/Feature/Tenancy/EmployeeInvitationsSchemaTest.php`
- Test: `tests/Feature/Dashboard/EmployeeInvitationsTest.php`

**Interfaces:**
- Consumes: `App\Models\Business::current()`, `App\Enums\Role::managers()` (Fase 2), `Route::middleware(['auth', 'business'])->prefix('dashboard')->name('dashboard.')` group (Task 1). `users.email` is globally unique (base migration) — invitation validation must check against it, since `AcceptInvitation` (Task 3) creates a `User` from the invitation's email with no other guard.
- Produces: `App\Models\EmployeeInvitation` (fillable `business_id, email, name, token, invited_by_id, expires_at, accepted_at`; casts `expires_at`/`accepted_at` datetime; `isExpired(): bool`, `isAccepted(): bool`, `scopePending(Builder $query): void`, `business(): BelongsTo`, `invitedBy(): BelongsTo`) — Task 3 reads `token`/`expires_at`/`business_id`/`email` off this model via an unscoped lookup. `InviteEmployee::handle(User $invitedBy, string $email, ?string $name): EmployeeInvitation`. `ResendInvitation::handle(EmployeeInvitation $invitation): EmployeeInvitation`. `RevokeInvitation::handle(EmployeeInvitation $invitation): void`. Named routes `dashboard.employees.index`, `dashboard.employees.invitations.{store,resend,destroy}`. `App\Notifications\EmployeeInvited` builds its accept link via `URL::temporarySignedRoute('invitations.accept', ...)` — **Task 3 must register a GET route named exactly `invitations.accept`**, or this notification will throw when sent.

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeInvitationsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_invitations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('employee_invitations'));
        $this->assertTrue(Schema::hasColumns('employee_invitations', [
            'id', 'business_id', 'email', 'name', 'token', 'invited_by_id',
            'expires_at', 'accepted_at', 'created_at', 'updated_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeInvitationsSchemaTest`
Expected: FAIL — `employee_invitations` table doesn't exist yet.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_invitations');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeInvitationsSchemaTest`
Expected: PASS

- [ ] **Step 5: Create the model and factory**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\EmployeeInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'email', 'name', 'token', 'invited_by_id', 'expires_at', 'accepted_at'])]
class EmployeeInvitation extends Model
{
    /** @use HasFactory<EmployeeInvitationFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeInvitation>
 */
class EmployeeInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'token' => Str::random(40),
            'invited_by_id' => User::factory()->owner(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()]);
    }
}
```

- [ ] **Step 6: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Notifications\EmployeeInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_employee(): void
    {
        Notification::fake();

        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post('/dashboard/employees/invitations', [
            'email' => 'nuevo@example.com',
            'name' => 'Nuevo Empleado',
        ]);

        $response->assertRedirect('/dashboard/employees');
        $this->assertDatabaseHas('employee_invitations', [
            'business_id' => $business->id,
            'email' => 'nuevo@example.com',
        ]);
        Notification::assertSentOnDemand(EmployeeInvited::class);
    }

    public function test_cannot_invite_same_email_twice_while_pending(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        EmployeeInvitation::factory()->for($business)->create([
            'email' => 'dup@example.com',
            'invited_by_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->post('/dashboard/employees/invitations', [
            'email' => 'dup@example.com',
        ]);

        $response->assertInvalid(['email']);
    }

    public function test_cannot_invite_email_already_registered_as_user(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($owner)->post('/dashboard/employees/invitations', [
            'email' => 'existing@example.com',
        ]);

        $response->assertInvalid(['email']);
    }

    public function test_employee_cannot_invite(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->post('/dashboard/employees/invitations', ['email' => 'x@example.com'])
            ->assertForbidden();
    }

    public function test_owner_can_resend_and_revoke_invitation(): void
    {
        Notification::fake();

        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->for($business)->create(['invited_by_id' => $owner->id]);
        $originalToken = $invitation->token;

        $this->actingAs($owner)->post("/dashboard/employees/invitations/{$invitation->id}/resend")
            ->assertRedirect('/dashboard/employees');
        $this->assertNotSame($originalToken, $invitation->fresh()->token);

        $this->actingAs($owner)->delete("/dashboard/employees/invitations/{$invitation->id}")
            ->assertRedirect('/dashboard/employees');
        $this->assertDatabaseMissing('employee_invitations', ['id' => $invitation->id]);
    }

    public function test_owner_cannot_manage_invitation_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $ownerB = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessB->id]);
        $invitationB = EmployeeInvitation::factory()->for($businessB)->create(['invited_by_id' => $ownerB->id]);

        $this->actingAs($ownerA)->post("/dashboard/employees/invitations/{$invitationB->id}/resend")
            ->assertNotFound();
        $this->actingAs($ownerA)->delete("/dashboard/employees/invitations/{$invitationB->id}")
            ->assertNotFound();
    }

    public function test_employees_index_only_lists_own_business_employees(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        User::factory()->create(['role' => Role::Employee, 'business_id' => $businessA->id, 'name' => 'Ana']);
        User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id, 'name' => 'Beto']);

        $this->actingAs($ownerA)->get('/dashboard/employees')
            ->assertInertia(fn ($page) => $page
                ->has('employees', 1)
                ->where('employees.0.name', 'Ana'));
    }
}
```

- [ ] **Step 7: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeInvitationsTest`
Expected: FAIL — no policy/actions/controllers/routes/notification yet.

- [ ] **Step 8: Create the policy, form request, notification, and actions**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\EmployeeInvitation;
use App\Models\User;

class EmployeeInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function manage(User $user, EmployeeInvitation $invitation): bool
    {
        return $user->business_id === $invitation->business_id
            && in_array($user->role, Role::managers(), true);
    }
}
```

```php
<?php

namespace App\Http\Requests\Dashboard;

use App\Models\EmployeeInvitation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email'),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (EmployeeInvitation::where('email', $value)->pending()->exists()) {
                        $fail('Ya existe una invitación pendiente para este email.');
                    }
                },
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

```php
<?php

namespace App\Notifications;

use App\Models\EmployeeInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class EmployeeInvited extends Notification
{
    use Queueable;

    public function __construct(private readonly EmployeeInvitation $invitation)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'invitations.accept',
            $this->invitation->expires_at,
            ['token' => $this->invitation->token],
        );

        return (new MailMessage)
            ->subject('Invitación para unirte a '.$this->invitation->business->name)
            ->line('Te invitaron a sumarte como empleado.')
            ->action('Aceptar invitación', $url)
            ->line('Este enlace vence en 7 días.');
    }
}
```

```php
<?php

namespace App\Actions\Employees;

use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Notifications\EmployeeInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteEmployee
{
    public function handle(User $invitedBy, string $email, ?string $name): EmployeeInvitation
    {
        $invitation = EmployeeInvitation::create([
            'email' => $email,
            'name' => $name,
            'token' => Str::random(40),
            'invited_by_id' => $invitedBy->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)->notify(new EmployeeInvited($invitation));

        return $invitation;
    }
}
```

```php
<?php

namespace App\Actions\Employees;

use App\Models\EmployeeInvitation;
use App\Notifications\EmployeeInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ResendInvitation
{
    public function handle(EmployeeInvitation $invitation): EmployeeInvitation
    {
        $invitation->update([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)->notify(new EmployeeInvited($invitation));

        return $invitation;
    }
}
```

```php
<?php

namespace App\Actions\Employees;

use App\Models\EmployeeInvitation;

class RevokeInvitation
{
    public function handle(EmployeeInvitation $invitation): void
    {
        $invitation->delete();
    }
}
```

- [ ] **Step 9: Create the controllers**

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', EmployeeInvitation::class);

        $business = Business::current();

        return Inertia::render('Dashboard/Employees/Index', [
            'employees' => User::query()
                ->where('business_id', $business->id)
                ->where('role', Role::Employee)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_active']),
            'invitations' => EmployeeInvitation::pending()->orderBy('created_at', 'desc')->get(),
        ]);
    }
}
```

Note: `User` has no tenant global scope (per Fase 2 — `business_id` is nullable for customers), so the employee list must filter by `business_id` explicitly. `EmployeeInvitation::pending()` is already scoped by `BelongsToBusiness`'s global scope, no explicit filter needed there.

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Employees\InviteEmployee;
use App\Actions\Employees\ResendInvitation;
use App\Actions\Employees\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\InviteEmployeeRequest;
use App\Models\EmployeeInvitation;
use Illuminate\Http\RedirectResponse;

class EmployeeInvitationController extends Controller
{
    public function store(InviteEmployeeRequest $request, InviteEmployee $action): RedirectResponse
    {
        $this->authorize('create', EmployeeInvitation::class);

        $action->handle($request->user(), $request->validated('email'), $request->validated('name'));

        return redirect()->route('dashboard.employees.index');
    }

    public function resend(EmployeeInvitation $invitation, ResendInvitation $action): RedirectResponse
    {
        $this->authorize('manage', $invitation);

        $action->handle($invitation);

        return redirect()->route('dashboard.employees.index');
    }

    public function destroy(EmployeeInvitation $invitation, RevokeInvitation $action): RedirectResponse
    {
        $this->authorize('manage', $invitation);

        $action->handle($invitation);

        return redirect()->route('dashboard.employees.index');
    }
}
```

- [ ] **Step 10: Add the routes**

```php
<?php

use App\Http\Controllers\Dashboard\EmployeeController;
use App\Http\Controllers\Dashboard\EmployeeInvitationController;
use App\Http\Controllers\Dashboard\ServiceController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::resource('services', ServiceController::class)->except(['show']);

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('employees/invitations', [EmployeeInvitationController::class, 'store'])->name('employees.invitations.store');
        Route::post('employees/invitations/{invitation}/resend', [EmployeeInvitationController::class, 'resend'])->name('employees.invitations.resend');
        Route::delete('employees/invitations/{invitation}', [EmployeeInvitationController::class, 'destroy'])->name('employees.invitations.destroy');
    });
});
```

- [ ] **Step 11: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeInvitationsTest`
Expected: PASS

- [ ] **Step 12: Add the Employees UI**

```jsx
import { router, useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Index({ employees, invitations }) {
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', name: '' });

    function invite(e) {
        e.preventDefault();
        post('/dashboard/employees/invitations', { onSuccess: () => reset() });
    }

    function resend(invitation) {
        router.post(`/dashboard/employees/invitations/${invitation.id}/resend`);
    }

    function revoke(invitation) {
        if (confirm(`¿Revocar invitación a ${invitation.email}?`)) {
            router.delete(`/dashboard/employees/invitations/${invitation.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <h1 className="mb-6 text-2xl font-bold">Empleados</h1>

                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Nombre</th>
                            <th className="py-2">Email</th>
                            <th className="py-2">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        {employees.map((employee) => (
                            <tr key={employee.id} className="border-b">
                                <td className="py-2">{employee.name}</td>
                                <td className="py-2">{employee.email}</td>
                                <td className="py-2">{employee.is_active ? 'Activo' : 'Inactivo'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Invitaciones pendientes</h2>
                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Email</th>
                            <th className="py-2">Vence</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {invitations.map((invitation) => (
                            <tr key={invitation.id} className="border-b">
                                <td className="py-2">{invitation.email}</td>
                                <td className="py-2">{invitation.expires_at}</td>
                                <td className="py-2 text-right">
                                    <button onClick={() => resend(invitation)} className="mr-4 underline">
                                        Reenviar
                                    </button>
                                    <button onClick={() => revoke(invitation)} className="text-red-600 underline">
                                        Revocar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Invitar empleado</h2>
                <form onSubmit={invite} className="max-w-sm space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Nombre (opcional)</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Email</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.email} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Enviar invitación
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
```

- [ ] **Step 13: Run the full test suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations. (`EmployeeInvitationsTest` fully passes even though `invitations.accept` doesn't exist until Task 3 — these tests use `Notification::fake()`, so the URL is never actually generated/resolved.)

- [ ] **Step 14: Commit**

```bash
git add database/migrations/2026_08_07_000002_create_employee_invitations_table.php app/Models/EmployeeInvitation.php \
  database/factories/EmployeeInvitationFactory.php app/Policies/EmployeeInvitationPolicy.php \
  app/Http/Requests/Dashboard/InviteEmployeeRequest.php app/Actions/Employees/InviteEmployee.php \
  app/Actions/Employees/ResendInvitation.php app/Actions/Employees/RevokeInvitation.php app/Notifications/EmployeeInvited.php \
  app/Http/Controllers/Dashboard/EmployeeController.php app/Http/Controllers/Dashboard/EmployeeInvitationController.php \
  routes/dashboard.php resources/js/Pages/Dashboard/Employees \
  tests/Feature/Tenancy/EmployeeInvitationsSchemaTest.php tests/Feature/Dashboard/EmployeeInvitationsTest.php
git commit -m "feat: add employee invitations (invite/resend/revoke)"
```

---

### Task 3: Accept invitation flow (public)

**Files:**
- Create: `app/Http/Controllers/Auth/InvitationAcceptController.php`
- Create: `app/Http/Requests/Auth/AcceptInvitationRequest.php`
- Create: `app/Actions/Employees/AcceptInvitation.php`
- Create: `routes/invitations.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Invitations/Accept.jsx`
- Test: `tests/Feature/Auth/InvitationAcceptTest.php`

**Interfaces:**
- Consumes: `App\Models\EmployeeInvitation` (`token`, `expires_at`, `business_id`, `email`, `isExpired()`, `isAccepted()`, `business()`) and the `App\Models\Scopes\BusinessScope` class (Task 2 / Fase 2). No `business` middleware is applied to this route (it's a public, unauthenticated flow) — every lookup must use `EmployeeInvitation::withoutGlobalScope(BusinessScope::class)`, or it throws `MissingBusinessContextException`.
- Produces: named route `invitations.accept` (`GET`, `signed` + throttled — this is the exact route name Task 2's `EmployeeInvited::toMail()` already calls `URL::temporarySignedRoute()` against) and a same-path `POST` route (unsigned, throttled) for the accept form submission. `AcceptInvitation::handle(EmployeeInvitation $invitation, string $name, string $password): User` — creates the employee `User` (`role = Employee`, `business_id` from the invitation) and marks the invitation `accepted_at` inside one DB transaction, mirroring `RegisterBusinessOwner` from Fase 2.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_a_valid_invitation_creates_an_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->for($business)->create([
            'invited_by_id' => $owner->id,
            'email' => 'ana@example.com',
        ]);

        $url = URL::temporarySignedRoute('invitations.accept', $invitation->expires_at, ['token' => $invitation->token]);
        $this->get($url)->assertOk();

        $response = $this->post("/invitations/{$invitation->token}/accept", [
            'token' => $invitation->token,
            'name' => 'Ana Empleada',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertDatabaseHas('users', [
            'email' => 'ana@example.com',
            'business_id' => $business->id,
            'role' => Role::Employee->value,
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertAuthenticated();
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->expired()->for($business)->create(['invited_by_id' => $owner->id]);

        $this->post("/invitations/{$invitation->token}/accept", [
            'token' => $invitation->token,
            'name' => 'X',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => $invitation->email]);
    }

    public function test_already_accepted_invitation_cannot_be_accepted_again(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->accepted()->for($business)->create(['invited_by_id' => $owner->id]);

        $this->post("/invitations/{$invitation->token}/accept", [
            'token' => $invitation->token,
            'name' => 'X',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->post('/invitations/not-a-real-token/accept', [
            'token' => 'not-a-real-token',
            'name' => 'X',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_show_rejects_unsigned_url(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->for($business)->create(['invited_by_id' => $owner->id]);

        $this->get("/invitations/{$invitation->token}/accept")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=InvitationAcceptTest`
Expected: FAIL — route `invitations.accept` doesn't exist yet.

- [ ] **Step 3: Create the request and action**

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
```

```php
<?php

namespace App\Actions\Employees;

use App\Enums\Role;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvitation
{
    public function handle(EmployeeInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'business_id' => $invitation->business_id,
                'role' => Role::Employee,
                'email_verified_at' => now(),
            ]);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });
    }
}
```

- [ ] **Step 4: Create the controller**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Employees\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\EmployeeInvitation;
use App\Models\Scopes\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitationAcceptController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->findAcceptable($token);

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'email' => $invitation->email,
            'businessName' => $invitation->business->name,
        ]);
    }

    public function store(AcceptInvitationRequest $request, AcceptInvitation $action): RedirectResponse
    {
        $invitation = $this->findAcceptable($request->validated('token'));

        $user = $action->handle($invitation, $request->validated('name'), $request->validated('password'));

        Auth::login($user);

        return redirect('/dashboard');
    }

    private function findAcceptable(string $token): EmployeeInvitation
    {
        $invitation = EmployeeInvitation::withoutGlobalScope(BusinessScope::class)
            ->where('token', $token)
            ->first();

        abort_if(! $invitation || $invitation->isAccepted() || $invitation->isExpired(), 404);

        return $invitation;
    }
}
```

- [ ] **Step 5: Add the routes**

```php
<?php

use App\Http\Controllers\Auth\InvitationAcceptController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('invitations/{token}/accept', [InvitationAcceptController::class, 'show'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('invitations.accept');

    Route::post('invitations/{token}/accept', [InvitationAcceptController::class, 'store'])
        ->middleware('throttle:6,1');
});
```

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Inertia::render('Home');
});

require __DIR__.'/auth.php';
require __DIR__.'/dashboard.php';
require __DIR__.'/invitations.php';
```

(the last block shows the full, unchanged-elsewhere `routes/web.php` for reference — the only edit is adding the `require __DIR__.'/invitations.php';` line; leave the existing `use Inertia\Inertia;` import and `/` route exactly as they are)

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=InvitationAcceptTest`
Expected: PASS

- [ ] **Step 7: Add the Accept UI**

```jsx
import { useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import InputError from '../../Components/InputError';

export default function Accept({ token, email, businessName }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        name: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/invitations/${token}/accept`);
    }

    return (
        <AuthCard title={`Unite a ${businessName}`}>
            <p className="mb-4 text-sm text-gray-600">Invitación para {email}</p>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700">Nombre</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        autoFocus
                    />
                    <InputError message={errors.name} />
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">Contraseña</label>
                    <input
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                    />
                    <InputError message={errors.password} />
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">Confirmar contraseña</label>
                    <input
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                    />
                    <InputError message={errors.password_confirmation} />
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                >
                    Crear cuenta
                </button>
            </form>
        </AuthCard>
    );
}
```

- [ ] **Step 8: Run the full test suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Auth/InvitationAcceptController.php app/Http/Requests/Auth/AcceptInvitationRequest.php \
  app/Actions/Employees/AcceptInvitation.php routes/invitations.php routes/web.php \
  resources/js/Pages/Invitations tests/Feature/Auth/InvitationAcceptTest.php
git commit -m "feat: add public invitation-accept flow"
```

---

### Task 4: `employee_service` pivot assignment (backend + UI)

**Files:**
- Create: `database/migrations/2026_08_07_000003_create_employee_service_table.php`
- Modify: `app/Models/User.php`
- Create: `app/Http/Requests/Dashboard/EmployeeServicesRequest.php`
- Create: `app/Actions/Employees/SyncEmployeeServices.php`
- Create: `app/Http/Controllers/Dashboard/EmployeeServiceController.php`
- Modify: `app/Http/Controllers/Dashboard/EmployeeController.php`
- Modify: `resources/js/Pages/Dashboard/Employees/Index.jsx`
- Modify: `routes/dashboard.php`
- Test: `tests/Feature/Tenancy/EmployeeServiceSchemaTest.php`
- Test: `tests/Feature/Dashboard/EmployeeServicesTest.php`

**Interfaces:**
- Consumes: `Service::employees(): BelongsToMany` (Task 1, pivot `employee_service`), `EmployeeController::index` / `Employees/Index.jsx` (Task 2 — both modified here to also expose services), `App\Policies\UserPolicy::update` (Fase 2 — reused as-is: "can this actor manage this employee" is exactly "same business + owner/admin", no new policy method needed).
- Produces: `User::services(): BelongsToMany` (pivot `employee_service`, `employee_id`/`service_id`, `withTimestamps()`). `SyncEmployeeServices::handle(User $employee, array<int, int> $serviceIds): void`. Route `dashboard.employees.services.update` (`PUT /dashboard/employees/{employee}/services`).
- Reminder from Global Constraints: `service_ids.*` is validated only for shape (`integer`) in the Form Request — cross-business IDs are rejected inside the Action via a scoped Eloquent query, not via `exists:services,id` (which would bypass `BusinessScope`).

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeServiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_service_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('employee_service'));
        $this->assertTrue(Schema::hasColumns('employee_service', [
            'employee_id', 'service_id', 'created_at', 'updated_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeServiceSchemaTest`
Expected: FAIL — `employee_service` table doesn't exist yet.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_service', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['employee_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_service');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeServiceSchemaTest`
Expected: PASS

- [ ] **Step 5: Add the `services()` relation to `User`**

Add this method to `app/Models/User.php` (alongside the existing `business()` relation), and add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and `use App\Models\Service;` to its imports:

```php
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'employee_service', 'employee_id', 'service_id')
            ->withTimestamps();
    }
```

- [ ] **Step 6: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_assign_services_to_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $serviceA = Service::factory()->for($business)->create();
        $serviceB = Service::factory()->for($business)->create();

        $response = $this->actingAs($owner)->put("/dashboard/employees/{$employee->id}/services", [
            'service_ids' => [$serviceA->id, $serviceB->id],
        ]);

        $response->assertRedirect('/dashboard/employees');
        $this->assertSame(
            [$serviceA->id, $serviceB->id],
            $employee->services()->pluck('services.id')->sort()->values()->all(),
        );
    }

    public function test_cannot_assign_service_from_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeA = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessA->id]);
        $serviceB = Service::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA)->put("/dashboard/employees/{$employeeA->id}/services", [
            'service_ids' => [$serviceB->id],
        ]);

        $response->assertInvalid(['service_ids']);
        $this->assertSame(0, $employeeA->services()->count());
    }

    public function test_cannot_assign_services_to_employee_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);

        $this->actingAs($ownerA)->put("/dashboard/employees/{$employeeB->id}/services", ['service_ids' => []])
            ->assertNotFound();
    }

    public function test_employee_cannot_assign_own_services(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->put("/dashboard/employees/{$employee->id}/services", ['service_ids' => []])
            ->assertForbidden();
    }
}
```

- [ ] **Step 7: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeServicesTest`
Expected: FAIL — no request/action/controller/route yet.

- [ ] **Step 8: Create the request and action**

```php
<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_ids' => ['array'],
            'service_ids.*' => ['integer'],
        ];
    }
}
```

```php
<?php

namespace App\Actions\Employees;

use App\Models\Service;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SyncEmployeeServices
{
    /**
     * @param  array<int, int>  $serviceIds
     */
    public function handle(User $employee, array $serviceIds): void
    {
        $services = Service::whereIn('id', $serviceIds)->get();

        if ($services->count() !== count(array_unique($serviceIds))) {
            throw ValidationException::withMessages([
                'service_ids' => 'Uno o más servicios no pertenecen a esta empresa.',
            ]);
        }

        $employee->services()->sync($services->pluck('id'));
    }
}
```

- [ ] **Step 9: Create the controller**

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Employees\SyncEmployeeServices;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\EmployeeServicesRequest;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class EmployeeServiceController extends Controller
{
    public function update(EmployeeServicesRequest $request, User $employee, SyncEmployeeServices $action): RedirectResponse
    {
        abort_unless(
            $employee->business_id === Business::current()->id && $employee->role === Role::Employee,
            404,
        );
        $this->authorize('update', $employee);

        $action->handle($employee, $request->validated('service_ids', []));

        return redirect()->route('dashboard.employees.index');
    }
}
```

`User` isn't tenant-scoped (Fase 2), so `{employee}` route-model-binding resolves across every business — the `abort_unless` closes that gap (404 for a wrong-business or non-employee target) before `$this->authorize('update', $employee)` runs the same-business/manager-role check from `UserPolicy` (Fase 2).

- [ ] **Step 10: Update `EmployeeController::index` to expose services**

Replace the whole method body:

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\Service;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', EmployeeInvitation::class);

        $business = Business::current();

        $employees = User::query()
            ->where('business_id', $business->id)
            ->where('role', Role::Employee)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active'])
            ->load('services:id')
            ->map(fn (User $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'is_active' => $employee->is_active,
                'service_ids' => $employee->services->pluck('id'),
            ]);

        return Inertia::render('Dashboard/Employees/Index', [
            'employees' => $employees,
            'invitations' => EmployeeInvitation::pending()->orderBy('created_at', 'desc')->get(),
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
```

- [ ] **Step 11: Add the route**

Add this line inside the existing `Route::prefix('dashboard')->name('dashboard.')->group(...)` block in `routes/dashboard.php`, alongside the `employees.*` routes from Task 2 (full file shown for reference — only the one `Route::put(...)` line is new):

```php
<?php

use App\Http\Controllers\Dashboard\EmployeeController;
use App\Http\Controllers\Dashboard\EmployeeInvitationController;
use App\Http\Controllers\Dashboard\EmployeeServiceController;
use App\Http\Controllers\Dashboard\ServiceController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::resource('services', ServiceController::class)->except(['show']);

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('employees/invitations', [EmployeeInvitationController::class, 'store'])->name('employees.invitations.store');
        Route::post('employees/invitations/{invitation}/resend', [EmployeeInvitationController::class, 'resend'])->name('employees.invitations.resend');
        Route::delete('employees/invitations/{invitation}', [EmployeeInvitationController::class, 'destroy'])->name('employees.invitations.destroy');
        Route::put('employees/{employee}/services', [EmployeeServiceController::class, 'update'])->name('employees.services.update');
    });
});
```

- [ ] **Step 12: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=EmployeeServicesTest`
Expected: PASS

- [ ] **Step 13: Add the services checkboxes to the Employees UI**

Replace the whole file:

```jsx
import { router, useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

function EmployeeServices({ employee, services }) {
    const { data, setData, put, processing } = useForm({ service_ids: employee.service_ids });

    function toggle(id) {
        setData(
            'service_ids',
            data.service_ids.includes(id)
                ? data.service_ids.filter((serviceId) => serviceId !== id)
                : [...data.service_ids, id],
        );
    }

    function save(e) {
        e.preventDefault();
        put(`/dashboard/employees/${employee.id}/services`);
    }

    return (
        <form onSubmit={save} className="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-600">
            {services.map((service) => (
                <label key={service.id} className="flex items-center gap-1">
                    <input
                        type="checkbox"
                        checked={data.service_ids.includes(service.id)}
                        onChange={() => toggle(service.id)}
                    />
                    {service.name}
                </label>
            ))}
            <button type="submit" disabled={processing} className="underline disabled:opacity-50">
                Guardar servicios
            </button>
        </form>
    );
}

export default function Index({ employees, invitations, services }) {
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', name: '' });

    function invite(e) {
        e.preventDefault();
        post('/dashboard/employees/invitations', { onSuccess: () => reset() });
    }

    function resend(invitation) {
        router.post(`/dashboard/employees/invitations/${invitation.id}/resend`);
    }

    function revoke(invitation) {
        if (confirm(`¿Revocar invitación a ${invitation.email}?`)) {
            router.delete(`/dashboard/employees/invitations/${invitation.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <h1 className="mb-6 text-2xl font-bold">Empleados</h1>

                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Nombre</th>
                            <th className="py-2">Email</th>
                            <th className="py-2">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        {employees.map((employee) => (
                            <tr key={employee.id} className="border-b align-top">
                                <td className="py-2">{employee.name}</td>
                                <td className="py-2">{employee.email}</td>
                                <td className="py-2">
                                    {employee.is_active ? 'Activo' : 'Inactivo'}
                                    <EmployeeServices employee={employee} services={services} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Invitaciones pendientes</h2>
                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Email</th>
                            <th className="py-2">Vence</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {invitations.map((invitation) => (
                            <tr key={invitation.id} className="border-b">
                                <td className="py-2">{invitation.email}</td>
                                <td className="py-2">{invitation.expires_at}</td>
                                <td className="py-2 text-right">
                                    <button onClick={() => resend(invitation)} className="mr-4 underline">
                                        Reenviar
                                    </button>
                                    <button onClick={() => revoke(invitation)} className="text-red-600 underline">
                                        Revocar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Invitar empleado</h2>
                <form onSubmit={invite} className="max-w-sm space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Nombre (opcional)</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Email</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.email} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Enviar invitación
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
```

- [ ] **Step 14: Run the full test suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 15: Commit**

```bash
git add database/migrations/2026_08_07_000003_create_employee_service_table.php app/Models/User.php \
  app/Http/Requests/Dashboard/EmployeeServicesRequest.php app/Actions/Employees/SyncEmployeeServices.php \
  app/Http/Controllers/Dashboard/EmployeeServiceController.php app/Http/Controllers/Dashboard/EmployeeController.php \
  resources/js/Pages/Dashboard/Employees/Index.jsx routes/dashboard.php \
  tests/Feature/Tenancy/EmployeeServiceSchemaTest.php tests/Feature/Dashboard/EmployeeServicesTest.php
git commit -m "feat: assign services to employees"
```

---

### Task 5: `schedules` + `schedule_breaks` (backend + UI)

**Files:**
- Create: `app/Enums/DayOfWeek.php`
- Create: `database/migrations/2026_08_07_000004_create_schedules_table.php`
- Create: `database/migrations/2026_08_07_000005_create_schedule_breaks_table.php`
- Create: `app/Models/Schedule.php`
- Create: `app/Models/ScheduleBreak.php`
- Create: `database/factories/ScheduleFactory.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `app/Models/User.php`
- Create: `app/Policies/SchedulePolicy.php`
- Create: `app/Http/Requests/Dashboard/ScheduleRequest.php`
- Create: `app/Http/Requests/Dashboard/ScheduleBreakRequest.php`
- Create: `app/Actions/Schedules/CreateSchedule.php`
- Create: `app/Actions/Schedules/UpdateSchedule.php`
- Create: `app/Actions/Schedules/DeleteSchedule.php`
- Create: `app/Actions/Schedules/AddScheduleBreak.php`
- Create: `app/Actions/Schedules/DeleteScheduleBreak.php`
- Create: `app/Http/Controllers/Dashboard/ScheduleController.php`
- Modify: `routes/dashboard.php`
- Modify: `resources/js/Pages/Dashboard/Employees/Index.jsx`
- Create: `resources/js/Pages/Dashboard/Employees/Schedule.jsx`
- Test: `tests/Feature/Tenancy/SchedulesSchemaTest.php`
- Test: `tests/Feature/Dashboard/SchedulesTest.php`

**Interfaces:**
- Consumes: the `abort_unless($employee->business_id === Business::current()->id && $employee->role === Role::Employee, 404)` guard pattern from Task 4's `EmployeeServiceController` (repeated here for the same reason: `User` route-model-binding isn't tenant-scoped).
- Produces: `App\Enums\DayOfWeek` (int-backed `0`–`6`, `Sunday` through `Saturday`, `label(): string` in Spanish). `App\Models\Schedule` (fillable `business_id, employee_id, day_of_week, start_time, end_time, is_active`; casts `day_of_week` → `DayOfWeek`, `is_active` bool; `employee(): BelongsTo`, `breaks(): HasMany`; uses `BelongsToBusiness`). `App\Models\ScheduleBreak` (fillable `schedule_id, start_time, end_time`; `schedule(): BelongsTo`; **no** `BelongsToBusiness` — authorized transitively via `$break->schedule`). `UserFactory::employee(): static` state (sets `role: Employee`, `business_id: Business::factory()`) — **Task 7's demo seeder reuses this state directly**, do not duplicate it there. `User::schedules(): HasMany`. Named routes: `dashboard.employees.schedule.index` (GET), `dashboard.employees.schedule.store` (POST), `dashboard.schedules.update` (PUT), `dashboard.schedules.destroy` (DELETE), `dashboard.schedules.breaks.store` (POST), `dashboard.schedule-breaks.destroy` (DELETE).
- Two correctness details load-bearing for this task: (1) `schedules.start_time`/`end_time` come back from Eloquent as **plain strings** (no cast applied) — comparing them with PHP's `<`/`>` operators is a latent bug (a break starting exactly at the schedule's `start_time` would incorrectly compare as "before" it, since `"09:00"` sorts before `"09:00:00"` lexicographically); always compare via `Illuminate\Support\Carbon::parse()`/`createFromFormat()`, never raw string comparison. (2) the design spec calls for a friendly validation error on same-day schedule overlap, not the raw `QueryException` from the DB `unique(['employee_id','day_of_week'])` constraint — `ScheduleRequest` checks for an existing row itself via a `withValidator()` hook before that constraint is ever hit.

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchedulesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedules_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('schedules'));
        $this->assertTrue(Schema::hasColumns('schedules', [
            'id', 'business_id', 'employee_id', 'day_of_week', 'start_time', 'end_time', 'is_active',
            'created_at', 'updated_at',
        ]));
    }

    public function test_schedule_breaks_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('schedule_breaks'));
        $this->assertTrue(Schema::hasColumns('schedule_breaks', [
            'id', 'schedule_id', 'start_time', 'end_time', 'created_at', 'updated_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=SchedulesSchemaTest`
Expected: FAIL — neither table exists yet.

- [ ] **Step 3: Create the `DayOfWeek` enum and the migrations**

```php
<?php

namespace App\Enums;

enum DayOfWeek: int
{
    case Sunday = 0;
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;

    public function label(): string
    {
        return match ($this) {
            self::Sunday => 'Domingo',
            self::Monday => 'Lunes',
            self::Tuesday => 'Martes',
            self::Wednesday => 'Miércoles',
            self::Thursday => 'Jueves',
            self::Friday => 'Viernes',
            self::Saturday => 'Sábado',
        };
    }
}
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
            $table->unique(['employee_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_breaks');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=SchedulesSchemaTest`
Expected: PASS

- [ ] **Step 5: Create the models, factory, and `UserFactory::employee()` state**

```php
<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'employee_id', 'day_of_week', 'start_time', 'end_time', 'is_active'])]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(ScheduleBreak::class);
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleBreak extends Model
{
    protected $fillable = ['schedule_id', 'start_time', 'end_time'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
```

`ScheduleBreak` uses the plain `$fillable` property (not the `#[Fillable]` attribute) and skips `HasFactory` — it's always created via `$schedule->breaks()->create(...)`, so no standalone factory is needed (YAGNI).

```php
<?php

namespace Database\Factories;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'employee_id' => User::factory()->employee(),
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ];
    }
}
```

Add this state to `database/factories/UserFactory.php`, alongside the existing `owner()`/`customer()` states:

```php
    /**
     * Indicate that the user is an employee of a new business.
     */
    public function employee(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Employee,
            'business_id' => Business::factory(),
        ]);
    }
```

- [ ] **Step 6: Add `schedules()` to `User`**

Add to `app/Models/User.php` (needs `use Illuminate\Database\Eloquent\Relations\HasMany;` added to its imports, alongside the `BelongsToMany` import from Task 4):

```php
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'employee_id');
    }
```

- [ ] **Step 7: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_schedule_for_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $response->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseHas('schedules', [
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => 1,
        ]);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '09:00',
        ])->assertInvalid(['end_time']);
    }

    public function test_cannot_create_two_schedules_same_day_for_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        Schedule::factory()->for($business)->create(['employee_id' => $employee->id, 'day_of_week' => 1]);

        $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertInvalid(['day_of_week']);
    }

    public function test_owner_can_add_break_within_schedule_range(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create([
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $response = $this->actingAs($owner)->post("/dashboard/schedules/{$schedule->id}/breaks", [
            'start_time' => '13:00',
            'end_time' => '14:00',
        ]);

        $response->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseHas('schedule_breaks', ['schedule_id' => $schedule->id]);
    }

    public function test_break_outside_schedule_range_is_rejected(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create([
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $this->actingAs($owner)->post("/dashboard/schedules/{$schedule->id}/breaks", [
            'start_time' => '08:00',
            'end_time' => '09:30',
        ])->assertInvalid(['start_time']);
    }

    public function test_break_exactly_at_schedule_boundaries_is_accepted(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create([
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $this->actingAs($owner)->post("/dashboard/schedules/{$schedule->id}/breaks", [
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
    }

    public function test_owner_can_delete_schedule_and_break(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $schedule = Schedule::factory()->for($business)->create(['employee_id' => $employee->id]);
        $break = $schedule->breaks()->create(['start_time' => '13:00', 'end_time' => '14:00']);

        $this->actingAs($owner)->delete("/dashboard/schedule-breaks/{$break->id}")
            ->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseMissing('schedule_breaks', ['id' => $break->id]);

        $this->actingAs($owner)->delete("/dashboard/schedules/{$schedule->id}")
            ->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
    }

    public function test_owner_cannot_manage_schedule_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);
        $scheduleB = Schedule::factory()->for($businessB)->create(['employee_id' => $employeeB->id]);

        $this->actingAs($ownerA)->get("/dashboard/employees/{$employeeB->id}/schedule")->assertNotFound();
        $this->actingAs($ownerA)->put("/dashboard/schedules/{$scheduleB->id}", [
            'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '18:00',
        ])->assertNotFound();
    }

    public function test_employee_cannot_manage_schedules(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->post("/dashboard/employees/{$employee->id}/schedule", [
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 8: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=SchedulesTest`
Expected: FAIL — no policy/requests/actions/controller/routes yet.

- [ ] **Step 9: Create the policy and form requests**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->business_id !== null;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $user->business_id === $schedule->business_id
            && in_array($user->role, Role::managers(), true);
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $this->update($user, $schedule);
    }
}
```

```php
<?php

namespace App\Http\Requests\Dashboard;

use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->route('employee')?->id ?? $this->route('schedule')?->employee_id;
            $scheduleId = $this->route('schedule')?->id;

            $exists = Schedule::where('employee_id', $employeeId)
                ->where('day_of_week', $this->input('day_of_week'))
                ->when($scheduleId, fn ($query) => $query->where('id', '!=', $scheduleId))
                ->exists();

            if ($exists) {
                $validator->errors()->add('day_of_week', 'Ya existe un horario para ese día.');
            }
        });
    }
}
```

```php
<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleBreakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }
}
```

- [ ] **Step 10: Create the actions**

```php
<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;

class CreateSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Schedule
    {
        return Schedule::create($data);
    }
}
```

```php
<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;

class UpdateSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Schedule $schedule, array $data): Schedule
    {
        $schedule->update($data);

        return $schedule;
    }
}
```

```php
<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;

class DeleteSchedule
{
    public function handle(Schedule $schedule): void
    {
        $schedule->delete();
    }
}
```

```php
<?php

namespace App\Actions\Schedules;

use App\Models\Schedule;
use App\Models\ScheduleBreak;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AddScheduleBreak
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Schedule $schedule, array $data): ScheduleBreak
    {
        // Eloquent returns `time` columns as plain strings — compare via Carbon, not `<`/`>`.
        $breakStart = Carbon::createFromFormat('H:i', $data['start_time']);
        $breakEnd = Carbon::createFromFormat('H:i', $data['end_time']);
        $scheduleStart = Carbon::parse($schedule->start_time);
        $scheduleEnd = Carbon::parse($schedule->end_time);

        if ($breakStart->lt($scheduleStart) || $breakEnd->gt($scheduleEnd)) {
            throw ValidationException::withMessages([
                'start_time' => 'La pausa debe estar dentro del horario del turno.',
            ]);
        }

        return $schedule->breaks()->create($data);
    }
}
```

```php
<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleBreak;

class DeleteScheduleBreak
{
    public function handle(ScheduleBreak $scheduleBreak): void
    {
        $scheduleBreak->delete();
    }
}
```

- [ ] **Step 11: Create the controller**

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Schedules\AddScheduleBreak;
use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\DeleteSchedule;
use App\Actions\Schedules\DeleteScheduleBreak;
use App\Actions\Schedules\UpdateSchedule;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ScheduleBreakRequest;
use App\Http\Requests\Dashboard\ScheduleRequest;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    public function index(User $employee): Response
    {
        $this->authorizeEmployee($employee);
        $this->authorize('viewAny', Schedule::class);

        return Inertia::render('Dashboard/Employees/Schedule', [
            'employee' => $employee->only(['id', 'name', 'email']),
            'schedules' => $employee->schedules()->with('breaks')->orderBy('day_of_week')->get(),
        ]);
    }

    public function store(ScheduleRequest $request, User $employee, CreateSchedule $action): RedirectResponse
    {
        $this->authorizeEmployee($employee);
        $this->authorize('create', Schedule::class);

        $action->handle([...$request->validated(), 'employee_id' => $employee->id]);

        return redirect()->route('dashboard.employees.schedule.index', $employee);
    }

    public function update(ScheduleRequest $request, Schedule $schedule, UpdateSchedule $action): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $action->handle($schedule, $request->validated());

        return redirect()->route('dashboard.employees.schedule.index', $schedule->employee_id);
    }

    public function destroy(Schedule $schedule, DeleteSchedule $action): RedirectResponse
    {
        $this->authorize('delete', $schedule);

        $employeeId = $schedule->employee_id;
        $action->handle($schedule);

        return redirect()->route('dashboard.employees.schedule.index', $employeeId);
    }

    public function storeBreak(ScheduleBreakRequest $request, Schedule $schedule, AddScheduleBreak $action): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $action->handle($schedule, $request->validated());

        return redirect()->route('dashboard.employees.schedule.index', $schedule->employee_id);
    }

    public function destroyBreak(ScheduleBreak $scheduleBreak, DeleteScheduleBreak $action): RedirectResponse
    {
        // ScheduleBreak has no BelongsToBusiness scope of its own — authorizing via
        // its parent schedule is what actually blocks cross-business access here.
        $this->authorize('update', $scheduleBreak->schedule);

        $employeeId = $scheduleBreak->schedule->employee_id;
        $action->handle($scheduleBreak);

        return redirect()->route('dashboard.employees.schedule.index', $employeeId);
    }

    private function authorizeEmployee(User $employee): void
    {
        abort_unless(
            $employee->business_id === Business::current()->id && $employee->role === Role::Employee,
            404,
        );
    }
}
```

- [ ] **Step 12: Add the routes**

Add these lines inside the existing `Route::prefix('dashboard')->name('dashboard.')->group(...)` block in `routes/dashboard.php`, and add `use App\Http\Controllers\Dashboard\ScheduleController;` to the file's imports:

```php
        Route::get('employees/{employee}/schedule', [ScheduleController::class, 'index'])->name('employees.schedule.index');
        Route::post('employees/{employee}/schedule', [ScheduleController::class, 'store'])->name('employees.schedule.store');
        Route::put('schedules/{schedule}', [ScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');
        Route::post('schedules/{schedule}/breaks', [ScheduleController::class, 'storeBreak'])->name('schedules.breaks.store');
        Route::delete('schedule-breaks/{scheduleBreak}', [ScheduleController::class, 'destroyBreak'])->name('schedule-breaks.destroy');
```

- [ ] **Step 13: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=SchedulesTest`
Expected: PASS

- [ ] **Step 14: Add the Schedule UI**

```jsx
import { Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

const DAYS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

function ScheduleBreakForm({ schedule }) {
    const { data, setData, post, processing, errors, reset } = useForm({ start_time: '', end_time: '' });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/schedules/${schedule.id}/breaks`, { onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="mt-2 flex items-end gap-2">
            <input
                type="time"
                value={data.start_time}
                onChange={(e) => setData('start_time', e.target.value)}
                className="rounded-md border-gray-300 text-xs shadow-sm"
            />
            <input
                type="time"
                value={data.end_time}
                onChange={(e) => setData('end_time', e.target.value)}
                className="rounded-md border-gray-300 text-xs shadow-sm"
            />
            <button type="submit" disabled={processing} className="text-xs underline disabled:opacity-50">
                Agregar pausa
            </button>
            <InputError message={errors.start_time} />
        </form>
    );
}

export default function Schedule({ employee, schedules }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        day_of_week: 1,
        start_time: '09:00',
        end_time: '18:00',
    });

    function addSchedule(e) {
        e.preventDefault();
        post(`/dashboard/employees/${employee.id}/schedule`, { onSuccess: () => reset('start_time', 'end_time') });
    }

    function removeSchedule(schedule) {
        if (confirm('¿Eliminar este horario?')) {
            router.delete(`/dashboard/schedules/${schedule.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <h1 className="mb-2 text-2xl font-bold">Horario de {employee.name}</h1>
                <Link href="/dashboard/employees" className="mb-6 inline-block text-sm underline">
                    Volver a empleados
                </Link>

                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Día</th>
                            <th className="py-2">Horario</th>
                            <th className="py-2">Pausas</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {schedules.map((schedule) => (
                            <tr key={schedule.id} className="border-b align-top">
                                <td className="py-2">{DAYS[schedule.day_of_week]}</td>
                                <td className="py-2">{schedule.start_time} - {schedule.end_time}</td>
                                <td className="py-2">
                                    {schedule.breaks.map((scheduleBreak) => (
                                        <div key={scheduleBreak.id} className="flex items-center gap-2">
                                            {scheduleBreak.start_time} - {scheduleBreak.end_time}
                                            <button
                                                onClick={() => router.delete(`/dashboard/schedule-breaks/${scheduleBreak.id}`)}
                                                className="text-red-600 underline"
                                            >
                                                Quitar
                                            </button>
                                        </div>
                                    ))}
                                    <ScheduleBreakForm schedule={schedule} />
                                </td>
                                <td className="py-2 text-right">
                                    <button onClick={() => removeSchedule(schedule)} className="text-red-600 underline">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Agregar horario</h2>
                <form onSubmit={addSchedule} className="flex max-w-xl flex-wrap items-end gap-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Día</label>
                        <select
                            value={data.day_of_week}
                            onChange={(e) => setData('day_of_week', Number(e.target.value))}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        >
                            {DAYS.map((day, index) => (
                                <option key={index} value={index}>{day}</option>
                            ))}
                        </select>
                        <InputError message={errors.day_of_week} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Desde</label>
                        <input
                            type="time"
                            value={data.start_time}
                            onChange={(e) => setData('start_time', e.target.value)}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.start_time} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Hasta</label>
                        <input
                            type="time"
                            value={data.end_time}
                            onChange={(e) => setData('end_time', e.target.value)}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.end_time} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Agregar
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
```

In `resources/js/Pages/Dashboard/Employees/Index.jsx`, add a "Horario" link next to each employee's status (inside the same `<td>` that renders `<EmployeeServices ... />` from Task 4):

```jsx
                                <td className="py-2">
                                    {employee.is_active ? 'Activo' : 'Inactivo'}
                                    {' · '}
                                    <Link href={`/dashboard/employees/${employee.id}/schedule`} className="underline">
                                        Horario
                                    </Link>
                                    <EmployeeServices employee={employee} services={services} />
                                </td>
```

This also requires adding `Link` to that file's `@inertiajs/react` import (`import { Link, router, useForm } from '@inertiajs/react';`).

- [ ] **Step 15: Run the full test suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 16: Commit**

```bash
git add app/Enums/DayOfWeek.php database/migrations/2026_08_07_000004_create_schedules_table.php \
  database/migrations/2026_08_07_000005_create_schedule_breaks_table.php app/Models/Schedule.php app/Models/ScheduleBreak.php \
  database/factories/ScheduleFactory.php database/factories/UserFactory.php app/Models/User.php app/Policies/SchedulePolicy.php \
  app/Http/Requests/Dashboard/ScheduleRequest.php app/Http/Requests/Dashboard/ScheduleBreakRequest.php app/Actions/Schedules \
  app/Http/Controllers/Dashboard/ScheduleController.php routes/dashboard.php resources/js/Pages/Dashboard/Employees \
  tests/Feature/Tenancy/SchedulesSchemaTest.php tests/Feature/Dashboard/SchedulesTest.php
git commit -m "feat: add weekly schedules and breaks per employee"
```

---

### Task 6: `time_offs` (backend + UI)

**Files:**
- Create: `database/migrations/2026_08_07_000006_create_time_offs_table.php`
- Create: `app/Models/TimeOff.php`
- Create: `database/factories/TimeOffFactory.php`
- Modify: `app/Models/User.php`
- Create: `app/Policies/TimeOffPolicy.php`
- Create: `app/Http/Requests/Dashboard/TimeOffRequest.php`
- Create: `app/Actions/Schedules/CreateTimeOff.php`
- Create: `app/Actions/Schedules/DeleteTimeOff.php`
- Create: `app/Http/Controllers/Dashboard/TimeOffController.php`
- Modify: `app/Http/Controllers/Dashboard/ScheduleController.php`
- Modify: `routes/dashboard.php`
- Modify: `resources/js/Pages/Dashboard/Employees/Schedule.jsx`
- Test: `tests/Feature/Tenancy/TimeOffsSchemaTest.php`
- Test: `tests/Feature/Dashboard/TimeOffsTest.php`

**Interfaces:**
- Consumes: the same `authorizeEmployee()`-style guard as Task 5 (`User` isn't tenant-scoped). `CreateTimeOff`/`DeleteTimeOff` live under the `App\Actions\Schedules` namespace, matching the approved design spec's grouping (not a new `App\Actions\TimeOffs` namespace).
- Produces: `App\Models\TimeOff` (fillable `business_id, employee_id, starts_at, ends_at, reason`; casts `starts_at`/`ends_at` datetime; `employee(): BelongsTo`; uses `BelongsToBusiness`, so `{timeOff}` route-model-binding is already tenant-filtered — no extra `abort_unless` needed on `destroy`, only on `store`, which takes `{employee}`). `User::timeOffs(): HasMany`. `App\Policies\TimeOffPolicy` (`create`/`delete` only — no update; editing means delete + recreate, matches how the UI is built). Named routes `dashboard.employees.time-offs.store`, `dashboard.time-offs.destroy`.

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TimeOffsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_offs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('time_offs'));
        $this->assertTrue(Schema::hasColumns('time_offs', [
            'id', 'business_id', 'employee_id', 'starts_at', 'ends_at', 'reason', 'created_at', 'updated_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=TimeOffsSchemaTest`
Expected: FAIL — `time_offs` table doesn't exist yet.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_offs');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=TimeOffsSchemaTest`
Expected: PASS

- [ ] **Step 5: Create the model and factory**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\TimeOffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'employee_id', 'starts_at', 'ends_at', 'reason'])]
class TimeOff extends Model
{
    /** @use HasFactory<TimeOffFactory> */
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeOff>
 */
class TimeOffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'employee_id' => User::factory()->employee(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addDays(2),
            'reason' => 'Vacaciones',
        ];
    }
}
```

- [ ] **Step 6: Add `timeOffs()` to `User`**

Add to `app/Models/User.php`, alongside `schedules()` from Task 5:

```php
    public function timeOffs(): HasMany
    {
        return $this->hasMany(TimeOff::class, 'employee_id');
    }
```

- [ ] **Step 7: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\Business;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeOffsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_time_off_for_employee(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addDay()->toDateTimeString(),
            'reason' => 'Vacaciones',
        ]);

        $response->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseHas('time_offs', [
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'reason' => 'Vacaciones',
        ]);
    }

    public function test_ends_at_must_be_after_starts_at(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->toDateTimeString(),
        ])->assertInvalid(['ends_at']);
    }

    public function test_owner_can_delete_time_off(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $timeOff = TimeOff::factory()->for($business)->create(['employee_id' => $employee->id]);

        $this->actingAs($owner)->delete("/dashboard/time-offs/{$timeOff->id}")
            ->assertRedirect("/dashboard/employees/{$employee->id}/schedule");
        $this->assertDatabaseMissing('time_offs', ['id' => $timeOff->id]);
    }

    public function test_owner_cannot_manage_time_off_of_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);
        $timeOffB = TimeOff::factory()->for($businessB)->create(['employee_id' => $employeeB->id]);

        $this->actingAs($ownerA)->post("/dashboard/employees/{$employeeB->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addDay()->toDateTimeString(),
        ])->assertNotFound();

        $this->actingAs($ownerA)->delete("/dashboard/time-offs/{$timeOffB->id}")->assertNotFound();
    }

    public function test_employee_cannot_manage_time_offs(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->post("/dashboard/employees/{$employee->id}/time-offs", [
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addDay()->toDateTimeString(),
        ])->assertForbidden();
    }
}
```

- [ ] **Step 8: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=TimeOffsTest`
Expected: FAIL — no policy/request/actions/controller/routes yet.

- [ ] **Step 9: Create the policy, request, and actions**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\TimeOff;
use App\Models\User;

class TimeOffPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, Role::managers(), true);
    }

    public function delete(User $user, TimeOff $timeOff): bool
    {
        return $user->business_id === $timeOff->business_id
            && in_array($user->role, Role::managers(), true);
    }
}
```

```php
<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class TimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

```php
<?php

namespace App\Actions\Schedules;

use App\Models\TimeOff;

class CreateTimeOff
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): TimeOff
    {
        return TimeOff::create($data);
    }
}
```

```php
<?php

namespace App\Actions\Schedules;

use App\Models\TimeOff;

class DeleteTimeOff
{
    public function handle(TimeOff $timeOff): void
    {
        $timeOff->delete();
    }
}
```

- [ ] **Step 10: Create the controller**

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Schedules\CreateTimeOff;
use App\Actions\Schedules\DeleteTimeOff;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\TimeOffRequest;
use App\Models\Business;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TimeOffController extends Controller
{
    public function store(TimeOffRequest $request, User $employee, CreateTimeOff $action): RedirectResponse
    {
        abort_unless(
            $employee->business_id === Business::current()->id && $employee->role === Role::Employee,
            404,
        );
        $this->authorize('create', TimeOff::class);

        $action->handle([...$request->validated(), 'employee_id' => $employee->id]);

        return redirect()->route('dashboard.employees.schedule.index', $employee);
    }

    public function destroy(TimeOff $timeOff, DeleteTimeOff $action): RedirectResponse
    {
        $this->authorize('delete', $timeOff);

        $employeeId = $timeOff->employee_id;
        $action->handle($timeOff);

        return redirect()->route('dashboard.employees.schedule.index', $employeeId);
    }
}
```

- [ ] **Step 11: Add `timeOffs` to `ScheduleController::index` and add the routes**

Replace `ScheduleController::index`'s body (from Task 5) with:

```php
    public function index(User $employee): Response
    {
        $this->authorizeEmployee($employee);
        $this->authorize('viewAny', Schedule::class);

        return Inertia::render('Dashboard/Employees/Schedule', [
            'employee' => $employee->only(['id', 'name', 'email']),
            'schedules' => $employee->schedules()->with('breaks')->orderBy('day_of_week')->get(),
            'timeOffs' => $employee->timeOffs()->orderBy('starts_at')->get(),
        ]);
    }
```

Add these lines inside `routes/dashboard.php`'s existing `Route::prefix('dashboard')->...` group, and add `use App\Http\Controllers\Dashboard\TimeOffController;` to the file's imports:

```php
        Route::post('employees/{employee}/time-offs', [TimeOffController::class, 'store'])->name('employees.time-offs.store');
        Route::delete('time-offs/{timeOff}', [TimeOffController::class, 'destroy'])->name('time-offs.destroy');
```

- [ ] **Step 12: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=TimeOffsTest`
Expected: PASS

- [ ] **Step 13: Add the time-offs section to the Schedule UI**

Replace the whole file:

```jsx
import { Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

const DAYS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

function ScheduleBreakForm({ schedule }) {
    const { data, setData, post, processing, errors, reset } = useForm({ start_time: '', end_time: '' });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/schedules/${schedule.id}/breaks`, { onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="mt-2 flex items-end gap-2">
            <input
                type="time"
                value={data.start_time}
                onChange={(e) => setData('start_time', e.target.value)}
                className="rounded-md border-gray-300 text-xs shadow-sm"
            />
            <input
                type="time"
                value={data.end_time}
                onChange={(e) => setData('end_time', e.target.value)}
                className="rounded-md border-gray-300 text-xs shadow-sm"
            />
            <button type="submit" disabled={processing} className="text-xs underline disabled:opacity-50">
                Agregar pausa
            </button>
            <InputError message={errors.start_time} />
        </form>
    );
}

function TimeOffForm({ employee }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        starts_at: '',
        ends_at: '',
        reason: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/employees/${employee.id}/time-offs`, { onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="flex max-w-xl flex-wrap items-end gap-4">
            <div>
                <label className="block text-sm font-medium text-gray-700">Desde</label>
                <input
                    type="datetime-local"
                    value={data.starts_at}
                    onChange={(e) => setData('starts_at', e.target.value)}
                    className="mt-1 block rounded-md border-gray-300 shadow-sm"
                />
                <InputError message={errors.starts_at} />
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700">Hasta</label>
                <input
                    type="datetime-local"
                    value={data.ends_at}
                    onChange={(e) => setData('ends_at', e.target.value)}
                    className="mt-1 block rounded-md border-gray-300 shadow-sm"
                />
                <InputError message={errors.ends_at} />
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700">Motivo (opcional)</label>
                <input
                    type="text"
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    className="mt-1 block rounded-md border-gray-300 shadow-sm"
                />
                <InputError message={errors.reason} />
            </div>
            <button
                type="submit"
                disabled={processing}
                className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
                Agregar
            </button>
        </form>
    );
}

export default function Schedule({ employee, schedules, timeOffs }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        day_of_week: 1,
        start_time: '09:00',
        end_time: '18:00',
    });

    function addSchedule(e) {
        e.preventDefault();
        post(`/dashboard/employees/${employee.id}/schedule`, { onSuccess: () => reset('start_time', 'end_time') });
    }

    function removeSchedule(schedule) {
        if (confirm('¿Eliminar este horario?')) {
            router.delete(`/dashboard/schedules/${schedule.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <h1 className="mb-2 text-2xl font-bold">Horario de {employee.name}</h1>
                <Link href="/dashboard/employees" className="mb-6 inline-block text-sm underline">
                    Volver a empleados
                </Link>

                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Día</th>
                            <th className="py-2">Horario</th>
                            <th className="py-2">Pausas</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {schedules.map((schedule) => (
                            <tr key={schedule.id} className="border-b align-top">
                                <td className="py-2">{DAYS[schedule.day_of_week]}</td>
                                <td className="py-2">{schedule.start_time} - {schedule.end_time}</td>
                                <td className="py-2">
                                    {schedule.breaks.map((scheduleBreak) => (
                                        <div key={scheduleBreak.id} className="flex items-center gap-2">
                                            {scheduleBreak.start_time} - {scheduleBreak.end_time}
                                            <button
                                                onClick={() => router.delete(`/dashboard/schedule-breaks/${scheduleBreak.id}`)}
                                                className="text-red-600 underline"
                                            >
                                                Quitar
                                            </button>
                                        </div>
                                    ))}
                                    <ScheduleBreakForm schedule={schedule} />
                                </td>
                                <td className="py-2 text-right">
                                    <button onClick={() => removeSchedule(schedule)} className="text-red-600 underline">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Agregar horario</h2>
                <form onSubmit={addSchedule} className="mb-8 flex max-w-xl flex-wrap items-end gap-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Día</label>
                        <select
                            value={data.day_of_week}
                            onChange={(e) => setData('day_of_week', Number(e.target.value))}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        >
                            {DAYS.map((day, index) => (
                                <option key={index} value={index}>{day}</option>
                            ))}
                        </select>
                        <InputError message={errors.day_of_week} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Desde</label>
                        <input
                            type="time"
                            value={data.start_time}
                            onChange={(e) => setData('start_time', e.target.value)}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.start_time} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Hasta</label>
                        <input
                            type="time"
                            value={data.end_time}
                            onChange={(e) => setData('end_time', e.target.value)}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.end_time} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Agregar
                    </button>
                </form>

                <h2 className="mb-4 text-lg font-semibold">Licencias</h2>
                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Desde</th>
                            <th className="py-2">Hasta</th>
                            <th className="py-2">Motivo</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {timeOffs.map((timeOff) => (
                            <tr key={timeOff.id} className="border-b">
                                <td className="py-2">{timeOff.starts_at}</td>
                                <td className="py-2">{timeOff.ends_at}</td>
                                <td className="py-2">{timeOff.reason}</td>
                                <td className="py-2 text-right">
                                    <button
                                        onClick={() => router.delete(`/dashboard/time-offs/${timeOff.id}`)}
                                        className="text-red-600 underline"
                                    >
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Agregar licencia</h2>
                <TimeOffForm employee={employee} />
            </div>
        </DashboardLayout>
    );
}
```

- [ ] **Step 14: Run the full test suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 15: Commit**

```bash
git add database/migrations/2026_08_07_000006_create_time_offs_table.php app/Models/TimeOff.php \
  database/factories/TimeOffFactory.php app/Models/User.php app/Policies/TimeOffPolicy.php \
  app/Http/Requests/Dashboard/TimeOffRequest.php app/Actions/Schedules/CreateTimeOff.php app/Actions/Schedules/DeleteTimeOff.php \
  app/Http/Controllers/Dashboard/TimeOffController.php app/Http/Controllers/Dashboard/ScheduleController.php \
  routes/dashboard.php resources/js/Pages/Dashboard/Employees/Schedule.jsx \
  tests/Feature/Tenancy/TimeOffsSchemaTest.php tests/Feature/Dashboard/TimeOffsTest.php
git commit -m "feat: add employee time-offs"
```

---

### Task 7: Demo seeder

**Files:**
- Create: `database/seeders/DemoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Seeders/DemoSeederTest.php`

**Interfaces:**
- Consumes: `UserFactory::employee()` (Task 5), `ServiceFactory` (Task 1), `ScheduleFactory` (Task 5), `App\Enums\DayOfWeek` (Task 5), `App\Enums\Role` (Fase 2). Reuses the explicit-`business_id`, no-`Business::current()` style from `RegisterBusinessOwner` (Fase 2) rather than the container-bound style used by the dashboard controllers.
- Produces: one demo business, owner login `owner@reservahub.test` / `password` (per `01-reservahub.md` §10), 2 employees (`ana@reservahub.test`, `beto@reservahub.test`), 5 services, a Mon–Fri 09:00–18:00 schedule for each employee (10 `schedules` rows total), and all 5 services assigned to both employees.
- **Load-bearing gotcha:** `database/seeders/DatabaseSeeder.php` already uses Laravel's `WithoutModelEvents` trait (present since the Fase 0 scaffold), which suppresses Eloquent's `creating` event for the *entire* seed run — including any seeder invoked via `$this->call(...)`. That means `BelongsToBusiness`'s auto-fill-`business_id`-on-create hook (the `static::creating()` closure from Fase 2, first actually exercised by the dashboard controllers in Tasks 1–6) **never fires during seeding**. `DemoSeeder` must not rely on it: every tenant-owned record is created via `->for($business)` (which sets `business_id` directly as a factory attribute, not via an event) or an explicit `'business_id' => $business->id`, never via a bare `Model::create([...])` that expects the hook to fill it in.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_expected_records(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseHas('users', ['email' => 'owner@reservahub.test', 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'ana@reservahub.test', 'role' => 'employee']);
        $this->assertDatabaseHas('users', ['email' => 'beto@reservahub.test', 'role' => 'employee']);
        $this->assertDatabaseCount('services', 5);
        $this->assertDatabaseCount('schedules', 10);
        $this->assertDatabaseCount('employee_service', 10);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=DemoSeederTest`
Expected: FAIL — `Database\Seeders\DemoSeeder` doesn't exist yet.

- [ ] **Step 3: Create the seeder**

```php
<?php

namespace Database\Seeders;

use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::create([
            'name' => 'Peluquería Demo',
            'slug' => 'peluqueria-demo',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'cancellation_hours' => 24,
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Owner Demo',
            'email' => 'owner@reservahub.test',
            'password' => 'password',
            'role' => Role::Owner,
            'business_id' => $business->id,
        ]);

        $employees = User::factory()
            ->count(2)
            ->sequence(
                ['name' => 'Ana Empleada', 'email' => 'ana@reservahub.test'],
                ['name' => 'Beto Empleado', 'email' => 'beto@reservahub.test'],
            )
            ->create([
                'password' => 'password',
                'role' => Role::Employee,
                'business_id' => $business->id,
            ]);

        $services = Service::factory()
            ->for($business)
            ->count(5)
            ->sequence(
                ['name' => 'Corte de cabello', 'duration_minutes' => 30, 'buffer_minutes' => 5, 'price' => 3500],
                ['name' => 'Coloración', 'duration_minutes' => 90, 'buffer_minutes' => 15, 'price' => 12000],
                ['name' => 'Manicura', 'duration_minutes' => 45, 'buffer_minutes' => 5, 'price' => 4000],
                ['name' => 'Masaje', 'duration_minutes' => 60, 'buffer_minutes' => 10, 'price' => 8000],
                ['name' => 'Depilación', 'duration_minutes' => 30, 'buffer_minutes' => 10, 'price' => 5000],
            )
            ->create();

        $weekdays = [DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday];

        foreach ($employees as $employee) {
            foreach ($weekdays as $day) {
                Schedule::factory()->for($business)->create([
                    'employee_id' => $employee->id,
                    'day_of_week' => $day,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                ]);
            }

            $employee->services()->sync($services->pluck('id'));
        }
    }
}
```

- [ ] **Step 4: Wire it into `DatabaseSeeder`**

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(DemoSeeder::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=DemoSeederTest`
Expected: PASS

- [ ] **Step 6: Run the full test suite and Pint**

Run: `docker compose exec laravel.test php artisan test && docker compose exec laravel.test vendor/bin/pint --test`
Expected: PASS, no style violations.

- [ ] **Step 7: Verify against a real fresh database**

Run: `docker compose exec laravel.test php artisan migrate:fresh --seed`
Expected: no errors; then log in at `/login` with `owner@reservahub.test` / `password` and confirm `/dashboard/services`, `/dashboard/employees`, and each employee's `/dashboard/employees/{id}/schedule` render the seeded data.

- [ ] **Step 8: Commit**

```bash
git add database/seeders/DemoSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Seeders/DemoSeederTest.php
git commit -m "feat: add demo seeder for business, employees, services and schedules"
```

---
