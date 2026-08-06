# Fase 2 — Empresas y tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `businesses` + user roles to ReservaHub, split registration into business-owner and customer paths, protect business-scoped routes with a resolved-tenant middleware, and land the `BelongsToBusiness` global-scope pattern that Fase 3+ tenant tables will reuse.

**Architecture:** Laravel 12 + Inertia/React, PHPUnit feature/unit tests, existing Action-per-use-case pattern under `app/Actions`. `businesses` is the tenant root; `users.business_id` is nullable (customers are cross-business). A `Business::current()` static accessor backed by a container binding is the single source of "which business is this request for" — the `EnsureBusinessContext` middleware sets it, `BusinessScope`/`BelongsToBusiness` and controllers read it.

**Tech Stack:** Laravel 12, PHP 8.3 backed enums, Inertia 3 + React (JSX, Tailwind utility classes, no component library), PHPUnit (not Pest) — see `tests/Feature/Auth/*Test.php` for the existing style.

## Global Constraints

- Spec of record: `docs/superpowers/specs/2026-08-06-fase2-tenancy-design.md` — every task below implements one of its sections.
- Every tenant-owned table must be filterable by `business_id`; this fase establishes the pattern (`BelongsToBusiness`), it does not yet apply it to a real domain table (that starts Fase 3).
- No employee/admin invitation flow, no services/schedules, no dashboard business logic beyond a placeholder page — explicitly out of scope per the spec.
- Follow existing conventions: PHPUnit `TestCase` classes (not Pest), `#[Fillable(...)]` attribute on models (not `$fillable` property, see `app/Models/User.php`), Actions as plain classes with a `handle()` method, Inertia pages under `resources/js/Pages`.
- Run `vendor/bin/pint --test` and `php artisan test` before each commit that touches PHP.

---

### Task 1: `Role` enum + `businesses`/`users` migrations

**Files:**
- Create: `app/Enums/Role.php`
- Create: `database/migrations/2026_08_06_000001_create_businesses_table.php`
- Create: `database/migrations/2026_08_06_000002_add_business_id_and_role_to_users_table.php`
- Test: `tests/Feature/Tenancy/SchemaTest.php`

**Interfaces:**
- Produces: `App\Enums\Role` backed enum with cases `Owner = 'owner'`, `Admin = 'admin'`, `Employee = 'employee'`, `Customer = 'customer'`. `businesses` table (`id, name, slug unique, timezone, currency, cancellation_hours, logo_path nullable, is_active, timestamps`). `users` table gains `business_id` (nullable FK → `businesses.id`, `nullOnDelete`, indexed), `role` (string, default `'customer'`), `is_active` (bool, default `true`).

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_businesses_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('businesses'));
        $this->assertTrue(Schema::hasColumns('businesses', [
            'id', 'name', 'slug', 'timezone', 'currency',
            'cancellation_hours', 'logo_path', 'is_active',
            'created_at', 'updated_at',
        ]));
    }

    public function test_users_table_has_tenancy_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['business_id', 'role', 'is_active']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SchemaTest`
Expected: FAIL — `businesses` table / columns don't exist yet.

- [ ] **Step 3: Create the `Role` enum**

```php
<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Employee = 'employee';
    case Customer = 'customer';
}
```

- [ ] **Step 4: Create the `businesses` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('UTC');
            $table->string('currency')->default('USD');
            $table->unsignedInteger('cancellation_hours')->default(24);
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
```

- [ ] **Step 5: Create the `users` alter migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')
                ->constrained('businesses')->nullOnDelete();
            $table->string('role')->default('customer')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=SchemaTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Enums/Role.php database/migrations/2026_08_06_000001_create_businesses_table.php database/migrations/2026_08_06_000002_add_business_id_and_role_to_users_table.php tests/Feature/Tenancy/SchemaTest.php
git commit -m "feat: add businesses table and user tenancy columns"
```

---

### Task 2: `Business` model + `User` model updates + factories

**Files:**
- Create: `app/Models/Business.php`
- Create: `database/factories/BusinessFactory.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Unit/Models/BusinessTest.php`
- Test: `tests/Unit/Models/UserTenancyTest.php`

**Interfaces:**
- Consumes: `businesses`/`users` columns from Task 1, `App\Enums\Role`.
- Produces: `Business::current(): ?Business` (reads a container binding, `null` if unbound). `Business::factory()` (name, unique slug). `User` gets `business()` (`BelongsTo`), `role` cast to `Role`, `isOwner(): bool`, `isAdmin(): bool`, `hasBusiness(): bool` (true when `business_id` not null), and `business_id`/`role`/`is_active` added to the `#[Fillable]` attribute. `User::factory()->owner()` / `->customer()` states used by later tasks' tests.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_null_when_unbound(): void
    {
        $this->assertNull(Business::current());
    }

    public function test_current_returns_bound_business(): void
    {
        $business = Business::factory()->create();
        app()->instance(Business::class, $business);

        $this->assertTrue(Business::current()->is($business));
    }

    public function test_factory_generates_unique_slug(): void
    {
        $a = Business::factory()->create(['name' => 'Peluquería Norte']);
        $b = Business::factory()->create(['name' => 'Peluquería Norte']);

        $this->assertNotSame($a->slug, $b->slug);
    }
}
```

```php
<?php

namespace Tests\Unit\Models;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_enum(): void
    {
        $user = User::factory()->create(['role' => Role::Owner]);

        $this->assertSame(Role::Owner, $user->fresh()->role);
    }

    public function test_role_helpers(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner]);
        $customer = User::factory()->create(['role' => Role::Customer, 'business_id' => null]);

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->isAdmin());
        $this->assertTrue($owner->hasBusiness());
        $this->assertFalse($customer->hasBusiness());
    }

    public function test_business_relation(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->assertTrue($user->business->is($business));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BusinessTest`
Run: `php artisan test --filter=UserTenancyTest`
Expected: FAIL — `Business` class / `User` helpers don't exist yet.

- [ ] **Step 3: Create the `Business` model**

```php
<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'timezone', 'currency', 'cancellation_hours', 'logo_path', 'is_active'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cancellation_hours' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }
}
```

- [ ] **Step 4: Create the `Business` factory**

```php
<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'cancellation_hours' => 24,
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 5: Update the `User` model**

```php
<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'business_id', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function hasBusiness(): bool
    {
        return $this->business_id !== null;
    }
}
```

- [ ] **Step 6: Update `UserFactory` with role-aware states**

Add to `database/factories/UserFactory.php` (keep existing `definition()`/`unverified()` as-is, add `role` to `definition()` and two new states):

```php
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => \App\Enums\Role::Customer,
            'business_id' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => \App\Enums\Role::Owner,
            'business_id' => \App\Models\Business::factory(),
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => \App\Enums\Role::Customer,
            'business_id' => null,
        ]);
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=BusinessTest`
Run: `php artisan test --filter=UserTenancyTest`
Expected: PASS

- [ ] **Step 8: Run full suite + Pint**

Run: `php artisan test`
Run: `vendor/bin/pint --test`
Expected: PASS (existing `RegistrationTest` etc. still green — Task 1/2 didn't touch registration behavior)

- [ ] **Step 9: Commit**

```bash
git add app/Models/Business.php app/Models/User.php database/factories/BusinessFactory.php database/factories/UserFactory.php tests/Unit/Models/BusinessTest.php tests/Unit/Models/UserTenancyTest.php
git commit -m "feat: add Business model and tenancy-aware User model"
```

---

### Task 3: Registration — business-owner and customer paths

**Files:**
- Create: `app/Actions/Auth/RegisterBusinessOwner.php`
- Create: `app/Actions/Auth/RegisterCustomer.php`
- Modify: `app/Http/Requests/Auth/RegisterRequest.php`
- Modify: `app/Http/Controllers/Auth/RegisteredUserController.php`
- Modify: `resources/js/Pages/Auth/Register.jsx`
- Modify: `tests/Feature/Auth/RegistrationTest.php`
- Test: `tests/Unit/Actions/Auth/RegisterBusinessOwnerTest.php`

**Interfaces:**
- Consumes: `App\Models\Business`, `App\Models\User`, `App\Enums\Role` (Task 1/2).
- Produces: `RegisterBusinessOwner::handle(string $name, string $email, string $password, string $businessName): User` (creates `Business` + owner `User` in one DB transaction). `RegisterCustomer::handle(string $name, string $email, string $password): User` (creates a `business_id`-null customer). Both consumed only by `RegisteredUserController::store`.

- [ ] **Step 1: Write the failing unit test for the transactional action**

```php
<?php

namespace Tests\Unit\Actions\Auth;

use App\Actions\Auth\RegisterBusinessOwner;
use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterBusinessOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_business_and_owner(): void
    {
        $user = (new RegisterBusinessOwner())->handle(
            name: 'Ana Owner',
            email: 'ana@example.com',
            password: 'password',
            businessName: 'Peluquería Norte',
        );

        $this->assertSame(Role::Owner, $user->role);
        $this->assertNotNull($user->business_id);
        $this->assertSame('Peluquería Norte', $user->business->name);
    }

    public function test_rolls_back_business_when_user_creation_fails(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        try {
            (new RegisterBusinessOwner())->handle(
                name: 'Otra Persona',
                email: 'taken@example.com',
                password: 'password',
                businessName: 'Otro Negocio',
            );
            $this->fail('Expected a QueryException for the duplicate email.');
        } catch (QueryException $e) {
            // expected: unique constraint on users.email
        }

        $this->assertSame(0, Business::where('name', 'Otro Negocio')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RegisterBusinessOwnerTest`
Expected: FAIL — `App\Actions\Auth\RegisterBusinessOwner` doesn't exist.

- [ ] **Step 3: Create the actions**

```php
<?php

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterBusinessOwner
{
    public function handle(string $name, string $email, string $password, string $businessName): User
    {
        return DB::transaction(function () use ($name, $email, $password, $businessName) {
            $business = Business::create([
                'name' => $businessName,
                'slug' => $this->uniqueSlug($businessName),
            ]);

            return User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'business_id' => $business->id,
                'role' => Role::Owner,
            ]);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Business::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
```

```php
<?php

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Models\User;

class RegisterCustomer
{
    public function handle(string $name, string $email, string $password): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'business_id' => null,
            'role' => Role::Customer,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RegisterBusinessOwnerTest`
Expected: PASS

- [ ] **Step 5: Update `RegisterRequest` with `account_type`**

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'account_type' => ['required', 'in:business,customer'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'business_name' => ['required_if:account_type,business', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 6: Update `RegisteredUserController`**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterBusinessOwner;
use App\Actions\Auth\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = $request->validated('account_type') === 'business'
            ? (new RegisterBusinessOwner())->handle(
                name: $request->validated('name'),
                email: $request->validated('email'),
                password: $request->validated('password'),
                businessName: $request->validated('business_name'),
            )
            : (new RegisterCustomer())->handle(
                name: $request->validated('name'),
                email: $request->validated('email'),
                password: $request->validated('password'),
            );

        event(new Registered($user));

        Auth::login($user);

        return redirect('/');
    }
}
```

- [ ] **Step 7: Update `Register.jsx` with the account-type toggle**

```jsx
import { Link, useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import InputError from '../../Components/InputError';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        account_type: 'business',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        business_name: '',
    });

    function submit(e) {
        e.preventDefault();
        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthCard title="Crear cuenta">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700">Tipo de cuenta</label>
                    <div className="mt-1 flex gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="account_type"
                                value="business"
                                checked={data.account_type === 'business'}
                                onChange={() => setData('account_type', 'business')}
                            />
                            Tengo un negocio
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="account_type"
                                value="customer"
                                checked={data.account_type === 'customer'}
                                onChange={() => setData('account_type', 'customer')}
                            />
                            Quiero reservar turnos
                        </label>
                    </div>
                    <InputError message={errors.account_type} />
                </div>
                {data.account_type === 'business' && (
                    <div>
                        <label htmlFor="business_name" className="block text-sm font-medium text-gray-700">
                            Nombre del negocio
                        </label>
                        <input
                            id="business_name"
                            type="text"
                            value={data.business_name}
                            onChange={(e) => setData('business_name', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.business_name} />
                    </div>
                )}
                <div>
                    <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                        Nombre
                    </label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        autoFocus
                    />
                    <InputError message={errors.name} />
                </div>
                <div>
                    <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                        Correo electrónico
                    </label>
                    <input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                    />
                    <InputError message={errors.email} />
                </div>
                <div>
                    <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                        Contraseña
                    </label>
                    <input
                        id="password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                    />
                    <InputError message={errors.password} />
                </div>
                <div>
                    <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-700">
                        Confirmar contraseña
                    </label>
                    <input
                        id="password_confirmation"
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
                    Registrarme
                </button>
                <p className="text-center text-sm text-gray-600">
                    ¿Ya tenés cuenta?{' '}
                    <Link href="/login" className="underline">
                        Iniciar sesión
                    </Link>
                </p>
            </form>
        </AuthCard>
    );
}
```

- [ ] **Step 8: Update `RegistrationTest` for both paths**

Replace the file contents with:

```php
<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
    }

    public function test_new_business_owner_can_register(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'business',
            'name' => 'Ana Owner',
            'email' => 'ana@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Peluquería Norte',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');

        $user = User::firstWhere('email', 'ana@example.com');
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame(Role::Owner, $user->role);
        $this->assertNotNull($user->business_id);
        $this->assertSame('Peluquería Norte', $user->business->name);
    }

    public function test_new_customer_can_register(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'customer',
            'name' => 'Carla Cliente',
            'email' => 'carla@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');

        $user = User::firstWhere('email', 'carla@example.com');
        $this->assertNotNull($user);
        $this->assertSame(Role::Customer, $user->role);
        $this->assertNull($user->business_id);
    }

    public function test_business_registration_requires_business_name(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'business',
            'name' => 'Ana Owner',
            'email' => 'ana@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('business_name');
        $this->assertGuest();
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'account_type' => 'customer',
            'name' => 'Otra Persona',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_business_slugs_are_unique(): void
    {
        Business::factory()->create(['name' => 'Peluquería Norte', 'slug' => 'peluqueria-norte']);

        $this->post('/register', [
            'account_type' => 'business',
            'name' => 'Otro Owner',
            'email' => 'otro@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Peluquería Norte',
        ]);

        $user = User::firstWhere('email', 'otro@example.com');
        $this->assertNotSame('peluqueria-norte', $user->business->slug);
    }
}
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=RegistrationTest`
Run: `php artisan test --filter=RegisterBusinessOwnerTest`
Expected: PASS

- [ ] **Step 10: Run full suite + Pint**

Run: `php artisan test`
Run: `vendor/bin/pint --test`
Expected: PASS

- [ ] **Step 11: Manual check**

Run: `npm run build` (or `npm run dev` and visit `/register` in browser)
Expected: page renders, toggling account type shows/hides "Nombre del negocio", no console errors.

- [ ] **Step 12: Commit**

```bash
git add app/Actions/Auth/RegisterBusinessOwner.php app/Actions/Auth/RegisterCustomer.php app/Http/Requests/Auth/RegisterRequest.php app/Http/Controllers/Auth/RegisteredUserController.php resources/js/Pages/Auth/Register.jsx tests/Feature/Auth/RegistrationTest.php tests/Unit/Actions/Auth/RegisterBusinessOwnerTest.php
git commit -m "feat: split registration into business-owner and customer paths"
```

---

### Task 4: `EnsureBusinessContext` middleware + dashboard placeholder route

**Files:**
- Create: `app/Http/Middleware/EnsureBusinessContext.php`
- Create: `app/Http/Controllers/DashboardController.php`
- Create: `resources/js/Pages/Dashboard/Index.jsx`
- Create: `routes/dashboard.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Middleware/EnsureBusinessContextTest.php`

**Interfaces:**
- Consumes: `App\Models\Business::current()` binding contract (Task 2), `User::hasBusiness()`, `User::role` (Task 2), `App\Enums\Role`.
- Produces: middleware alias `business` usable in any future route group. Binds `App\Models\Business` into the container for the duration of the request when the check passes — later tasks' controllers/scopes read it via `Business::current()` or constructor/method injection.

- [ ] **Step 1: Write the failing middleware test**

```php
<?php

namespace Tests\Feature\Middleware;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureBusinessContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_user_without_business_gets_403(): void
    {
        $customer = User::factory()->create(['role' => Role::Customer, 'business_id' => null]);

        $response = $this->actingAs($customer)->get('/dashboard');

        $response->assertForbidden();
    }

    public function test_owner_can_access_dashboard_with_business_bound(): void
    {
        $business = Business::factory()->create(['name' => 'Peluquería Norte']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('business.name', 'Peluquería Norte'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EnsureBusinessContextTest`
Expected: FAIL — route `/dashboard` doesn't exist (404).

- [ ] **Step 3: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasBusiness() || ! in_array($user->role, [Role::Owner, Role::Admin, Role::Employee], true)) {
            abort(403);
        }

        app()->instance(Business::class, $user->business);

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the `business` middleware alias**

In `bootstrap/app.php`, update `withMiddleware`:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'business' => \App\Http\Middleware\EnsureBusinessContext::class,
        ]);
    })
```

- [ ] **Step 5: Create `DashboardController`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Business $business): Response
    {
        return Inertia::render('Dashboard/Index', [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
            ],
        ]);
    }
}
```

- [ ] **Step 6: Create the placeholder Inertia page**

```jsx
export default function Index({ business }) {
    return (
        <div className="p-8">
            <h1 className="text-2xl font-bold">Panel de {business.name}</h1>
            <p className="mt-2 text-sm text-gray-600">
                El dashboard real (reservas de hoy, ingresos, etc.) llega en una fase posterior.
            </p>
        </div>
    );
}
```

- [ ] **Step 7: Create `routes/dashboard.php` and require it from `routes/web.php`**

`routes/dashboard.php`:

```php
<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});
```

`routes/web.php` — add the require:

```php
<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
});

require __DIR__.'/auth.php';
require __DIR__.'/dashboard.php';
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=EnsureBusinessContextTest`
Expected: PASS

- [ ] **Step 9: Run full suite + Pint**

Run: `php artisan test`
Run: `vendor/bin/pint --test`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Http/Middleware/EnsureBusinessContext.php app/Http/Controllers/DashboardController.php resources/js/Pages/Dashboard/Index.jsx routes/dashboard.php routes/web.php bootstrap/app.php tests/Feature/Middleware/EnsureBusinessContextTest.php
git commit -m "feat: add EnsureBusinessContext middleware and dashboard placeholder"
```

---

### Task 5: `BelongsToBusiness` trait + `BusinessScope`

**Files:**
- Create: `app/Models/Scopes/BusinessScope.php`
- Create: `app/Models/Concerns/BelongsToBusiness.php`
- Test: `tests/Unit/Models/Concerns/BelongsToBusinessTest.php`

**Interfaces:**
- Consumes: `Business::current()` (Task 2).
- Produces: `App\Models\Concerns\BelongsToBusiness` trait — any Eloquent model using it gets queries auto-filtered to `Business::current()->id` (no-op when unbound) and `business_id` auto-filled on create when not already set. This is the pattern Fase 3's `Service`/`Schedule` models will adopt; no production model uses it yet.

- [ ] **Step 1: Write the failing unit test (with a scratch table)**

```php
<?php

namespace Tests\Unit\Models\Concerns;

use App\Models\Business;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BelongsToBusinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scope_test_models', function ($table) {
            $table->id();
            $table->foreignId('business_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scope_test_models');

        parent::tearDown();
    }

    public function test_query_is_scoped_to_current_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        ScopeTestModel::unguard();
        ScopeTestModel::create(['business_id' => $businessA->id, 'name' => 'A']);
        ScopeTestModel::create(['business_id' => $businessB->id, 'name' => 'B']);
        ScopeTestModel::reguard();

        app()->instance(Business::class, $businessA);

        $this->assertSame(1, ScopeTestModel::count());
        $this->assertSame('A', ScopeTestModel::first()->name);
    }

    public function test_query_is_unfiltered_when_no_business_bound(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        ScopeTestModel::unguard();
        ScopeTestModel::create(['business_id' => $businessA->id, 'name' => 'A']);
        ScopeTestModel::create(['business_id' => $businessB->id, 'name' => 'B']);
        ScopeTestModel::reguard();

        $this->assertSame(2, ScopeTestModel::count());
    }

    public function test_business_id_is_auto_filled_on_create(): void
    {
        $business = Business::factory()->create();
        app()->instance(Business::class, $business);

        ScopeTestModel::unguard();
        $model = ScopeTestModel::create(['name' => 'Auto']);
        ScopeTestModel::reguard();

        $this->assertSame($business->id, $model->business_id);
    }
}

class ScopeTestModel extends Model
{
    use BelongsToBusiness;

    protected $table = 'scope_test_models';
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BelongsToBusinessTest`
Expected: FAIL — `App\Models\Concerns\BelongsToBusiness` doesn't exist.

- [ ] **Step 3: Create `BusinessScope`**

```php
<?php

namespace App\Models\Scopes;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($business = Business::current()) {
            $builder->where($model->getTable().'.business_id', $business->id);
        }
    }
}
```

- [ ] **Step 4: Create `BelongsToBusiness`**

```php
<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function (Model $model) {
            if (! $model->business_id && $business = Business::current()) {
                $model->business_id = $business->id;
            }
        });
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=BelongsToBusinessTest`
Expected: PASS

- [ ] **Step 6: Run full suite + Pint**

Run: `php artisan test`
Run: `vendor/bin/pint --test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Models/Scopes/BusinessScope.php app/Models/Concerns/BelongsToBusiness.php tests/Unit/Models/Concerns/BelongsToBusinessTest.php
git commit -m "feat: add BelongsToBusiness global-scope trait"
```

---

### Task 6: `BusinessPolicy` + `UserPolicy` (cross-business isolation)

**Files:**
- Create: `app/Policies/BusinessPolicy.php`
- Create: `app/Policies/UserPolicy.php`
- Test: `tests/Feature/Policies/BusinessPolicyTest.php`
- Test: `tests/Feature/Policies/UserPolicyTest.php`

**Interfaces:**
- Consumes: `App\Models\Business`, `App\Models\User`, `App\Enums\Role` (Task 1/2). Auto-discovered by Laravel's default policy-guessing convention (`App\Models\X` → `App\Policies\XPolicy`) — no provider registration needed.
- Produces: `BusinessPolicy::view/update(User $user, Business $business): bool`. `UserPolicy::update/delete(User $user, User $target): bool`. Both are the enforcement point for "no cross-business access" going forward.

- [ ] **Step 1: Write the failing policy tests**

```php
<?php

namespace Tests\Feature\Policies;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_own_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->assertTrue($owner->can('view', $business));
        $this->assertTrue($owner->can('update', $business));
    }

    public function test_owner_cannot_manage_another_business(): void
    {
        $ownBusiness = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $ownBusiness->id]);

        $this->assertFalse($owner->can('view', $otherBusiness));
        $this->assertFalse($owner->can('update', $otherBusiness));
    }

    public function test_employee_cannot_manage_business_settings(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->assertFalse($employee->can('update', $business));
    }
}
```

```php
<?php

namespace Tests\Feature\Policies;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_user_in_same_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->assertTrue($owner->can('update', $employee));
        $this->assertTrue($owner->can('delete', $employee));
    }

    public function test_owner_cannot_manage_user_in_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);

        $this->assertFalse($ownerA->can('update', $employeeB));
        $this->assertFalse($ownerA->can('delete', $employeeB));
    }

    public function test_employee_cannot_manage_other_users(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $coworker = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->assertFalse($employee->can('update', $coworker));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BusinessPolicyTest`
Run: `php artisan test --filter=UserPolicyTest`
Expected: FAIL — policies don't exist, `can()` returns `false` for everything (assertTrue cases fail).

- [ ] **Step 3: Create `BusinessPolicy`**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $user->business_id === $business->id
            && in_array($user->role, [Role::Owner, Role::Admin], true);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->view($user, $business);
    }
}
```

- [ ] **Step 4: Create `UserPolicy`**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function update(User $user, User $target): bool
    {
        return $user->business_id !== null
            && $user->business_id === $target->business_id
            && in_array($user->role, [Role::Owner, Role::Admin], true);
    }

    public function delete(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=BusinessPolicyTest`
Run: `php artisan test --filter=UserPolicyTest`
Expected: PASS

- [ ] **Step 6: Run full suite + Pint (final check for the fase)**

Run: `php artisan test`
Run: `vendor/bin/pint --test`
Expected: PASS — every test added across Tasks 1–6 plus the pre-existing Fase 1 suite green.

- [ ] **Step 7: Commit**

```bash
git add app/Policies/BusinessPolicy.php app/Policies/UserPolicy.php tests/Feature/Policies/BusinessPolicyTest.php tests/Feature/Policies/UserPolicyTest.php
git commit -m "feat: add Business and User policies for cross-tenant isolation"
```
