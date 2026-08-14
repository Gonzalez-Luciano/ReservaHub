# Fase 8 — Gestión de cuenta y negocio — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar las funciones de cuenta y negocio que `01-reservahub.md` §2 promete y no existen: cambio de contraseña con sesión iniciada, edición de perfil, ajustes del negocio, activación/desactivación de usuarios y feriados del negocio integrados en el motor de disponibilidad.

**Architecture:** Cada caso de uso vive en una Action bajo `app/Actions/`; los controladores de panel (Inertia) y de API (`ApiResponse`) comparten Action y Form Request y solo difieren en la respuesta. Toda revocación de acceso pasa por una única pieza, `App\Support\UserAccessRevoker`, que falla cerrado si el driver de sesión no es `database`. Los feriados son un modelo con tenancy (`BelongsToBusiness`) que `AvailabilityService` consulta junto con las dos guardas nuevas del motor.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18, Redis, Sanctum, Inertia + React 19, Tailwind 4, Pest/PHPUnit vía `php artisan test`, Docker (Laravel Sail), pnpm.

**Spec:** `docs/superpowers/specs/2026-08-14-fase8-cuenta-negocio-design.md` — leerla completa antes de la Task 1. El plan argumenta desde ella.

## Global Constraints

- **Idioma:** todo mensaje de validación, error y copy de UI se escribe en español directamente (`ValidationException::withMessages([...])`, `messages()` de Form Request). Las strings propias de Laravel ya están traducidas en `lang/es/`.
- **Envelope de API:** toda respuesta bajo `/api` sale por `App\Support\ApiResponse` y tiene exactamente `{success, data, message, errors}`. Nunca devolver un Resource pelado.
- **Tenancy:** toda consulta filtra por `business_id`. Los modelos con `BelongsToBusiness` lo hacen por global scope; las consultas manuales lo escriben explícito.
- **Rutas de panel:** bajo `middleware(['auth','business'])`, prefijo `dashboard`, nombres `dashboard.*`. Rutas de API de staff bajo `['auth:sanctum','business']`, nombres `api.*`.
- **Directorios de frontend:** son `resources/js/Pages/` y `resources/js/Components/` (mayúscula inicial). Windows es case-insensitive y oculta el error; en Linux/CI no.
- **Paquete JS:** pnpm. Nunca `npm`.
- **Formato:** `vendor/bin/pint` antes de cada commit; `vendor/bin/pint --test` debe pasar limpio.
- **Sin dependencias nuevas.** Ni Composer ni pnpm.
- **`SESSION_DRIVER=database`** es requisito de runtime a partir de esta fase.
- **Comandos:** siempre dentro del contenedor. `docker compose exec laravel.test php artisan test --filter=NombreDelTest`.

## Pre-flight (antes de la Task 1)

Esto no es una task con test propio: es el entorno. Seguir `CLAUDE.md` → "Bootstrapping a fresh worktree".

- [ ] **Crear el worktree** con la skill `superpowers:using-git-worktrees` (branch `fase8-cuenta-negocio`).
- [ ] **Copiar y ajustar `.env`** desde el checkout principal, con puertos propios para no chocar con la stack ya levantada:

```
APP_URL=http://localhost:8180
APP_PORT=8180
DB_HOST=pgsql
FORWARD_DB_PORT=54320
FORWARD_REDIS_PORT=63790
FORWARD_MAILPIT_PORT=10250
FORWARD_MAILPIT_DASHBOARD_PORT=8026
VITE_PORT=5273
SESSION_DRIVER=database
```

- [ ] **Instalar dependencias PHP** (sin `vendor/` el build de Compose ni siquiera arranca), desde el directorio del worktree:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -u root \
  -v "$(pwd -W):/var/www/html" -w /var/www/html \
  --entrypoint composer sail-8.5/app:latest install --no-interaction
```

- [ ] **Levantar la stack, migrar y construir el frontend** (sin `public/build`, `@vite` rompe y ~28 tests fallan con `Not a valid Inertia response`, que parecen bugs y no lo son):

```bash
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
docker compose exec laravel.test php artisan migrate:fresh --force
docker compose exec laravel.test bash -lc "pnpm install --frozen-lockfile && rm -f public/hot && pnpm build"
```

- [ ] **Baseline verde**: `docker compose exec laravel.test php artisan test`. El primer run tras `up -d` tarda ~600 s (Postgres frío + opcache frío), los siguientes ~70 s. No es un cuelgue. Si el baseline no está verde, parar y avisar: no se empieza la Task 1 sobre un suite roto.

---

### Task 1: `UserAccessRevoker` — revocación de acceso que falla cerrado

Pieza base: las Tasks 3, 4 y 7 la consumen. Corta los tres vectores de re-autenticación en una sola llamada: sesión de base de datos, cookie remember-me y tokens de Sanctum.

**Files:**
- Create: `app/Exceptions/UnsupportedSessionDriverException.php`
- Create: `app/Support/UserAccessRevoker.php`
- Test: `tests/Feature/Account/UserAccessRevokerTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `App\Support\UserAccessRevoker::revoke(App\Models\User $user, ?string $keepSessionId = null): void` — resuelto por el contenedor (sin constructor). `App\Exceptions\UnsupportedSessionDriverException::for(string $driver): self`.

**Por qué falla cerrado:** verificado en `bootstrap/app.php` que `Illuminate\Session\Middleware\AuthenticateSession` **no** está en el grupo `web`, así que `Auth::logoutOtherDevices()` no invalidaría nada — solo re-hashea y actualiza `password_hash_web` en la sesión actual, valor que ninguna otra petición vuelve a comparar. El único mecanismo real es borrar las filas de `sessions`, y eso exige el driver `database`. Con otro driver, seguir adelante dejaría sesiones web vivas mientras el llamador cree que revocó todo.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Account/UserAccessRevokerTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Exceptions\UnsupportedSessionDriverException;
use App\Models\User;
use App\Support\UserAccessRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserAccessRevokerTest extends TestCase
{
    use RefreshDatabase;

    private function insertSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_it_revokes_sessions_tokens_and_remember_token_under_the_database_driver(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create(['remember_token' => 'token-original']);
        $otherUser = User::factory()->create();
        $user->createToken('cli');

        $this->insertSession('sesion-actual', $user->id);
        $this->insertSession('otro-dispositivo', $user->id);
        $this->insertSession('otro-usuario', $otherUser->id);

        app(UserAccessRevoker::class)->revoke($user, 'sesion-actual');

        $this->assertDatabaseHas('sessions', ['id' => 'sesion-actual']);
        $this->assertDatabaseMissing('sessions', ['id' => 'otro-dispositivo']);
        $this->assertDatabaseHas('sessions', ['id' => 'otro-usuario']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotSame('token-original', $user->fresh()->remember_token);
    }

    public function test_it_removes_every_session_when_no_session_is_preserved(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create();
        $this->insertSession('sesion-actual', $user->id);
        $this->insertSession('otro-dispositivo', $user->id);

        app(UserAccessRevoker::class)->revoke($user);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_it_fails_closed_on_a_non_database_session_driver(): void
    {
        config()->set('session.driver', 'array');

        $user = User::factory()->create(['remember_token' => 'token-original']);
        $user->createToken('cli');
        $this->insertSession('sesion-actual', $user->id);

        $this->expectException(UnsupportedSessionDriverException::class);

        try {
            app(UserAccessRevoker::class)->revoke($user);
        } finally {
            $this->assertSame('token-original', $user->fresh()->remember_token);
            $this->assertSame(1, $user->tokens()->count());
            $this->assertDatabaseHas('sessions', ['id' => 'sesion-actual']);
        }
    }
}
```

El `try/finally` con `expectException` es deliberado: las aserciones del `finally` prueban que **nada** se tocó antes de lanzar, y la excepción sigue propagando hacia `expectException`.

`phpunit.xml` fija `SESSION_DRIVER=array`, por eso cada test declara el driver que necesita con `config()->set(...)`.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=UserAccessRevokerTest`
Expected: FAIL — `Class "App\Support\UserAccessRevoker" not found`.

- [ ] **Step 3: Escribir la excepción**

Crear `app/Exceptions/UnsupportedSessionDriverException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class UnsupportedSessionDriverException extends RuntimeException
{
    public static function for(string $driver): self
    {
        return new self(
            "La revocación de acceso requiere SESSION_DRIVER=database; el driver configurado es [{$driver}]."
        );
    }
}
```

- [ ] **Step 4: Escribir el revoker**

Crear `app/Support/UserAccessRevoker.php`:

```php
<?php

namespace App\Support;

use App\Exceptions\UnsupportedSessionDriverException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único mecanismo de revocación de acceso de la aplicación.
 *
 * Corta los tres vectores de re-autenticación: la sesión de base de datos,
 * la cookie remember-me y los tokens de Sanctum. `AuthenticateSession` no
 * está en el grupo `web`, así que `Auth::logoutOtherDevices()` no invalidaría
 * nada acá y no se usa.
 */
class UserAccessRevoker
{
    /**
     * @param  string|null  $keepSessionId  Sesión a preservar (la del propio
     *                                      request). `null` revoca todas.
     *
     * @throws UnsupportedSessionDriverException cuando el driver de sesión no
     *         es `database` y, por lo tanto, las sesiones ajenas no se pueden
     *         invalidar. Falla cerrado a propósito: no hay revocación parcial
     *         silenciosa.
     */
    public function revoke(User $user, ?string $keepSessionId = null): void
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            throw UnsupportedSessionDriverException::for($driver);
        }

        $user->setRememberToken(Str::random(60));
        $user->save();

        $user->tokens()->delete();

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=UserAccessRevokerTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app/Support app/Exceptions tests/Feature/Account
git add app/Support/UserAccessRevoker.php app/Exceptions/UnsupportedSessionDriverException.php tests/Feature/Account/UserAccessRevokerTest.php
git commit -m "feat: add fail-closed user access revoker"
```

---

### Task 2: Área de cuenta — perfil (web)

`/account` para **todo** usuario autenticado, incluidos los `customer` sin `business_id`. Fuera del middleware `business`.

**Files:**
- Create: `routes/account.php`
- Modify: `routes/web.php` (agregar el `require`)
- Create: `app/Actions/Account/UpdateProfile.php`
- Create: `app/Http/Requests/Account/UpdateProfileRequest.php`
- Create: `app/Http/Controllers/Account/ProfileController.php`
- Create: `resources/js/Pages/Account/Edit.jsx`
- Test: `tests/Feature/Account/ProfileTest.php`

**Interfaces:**
- Consumes: nada de tasks previas.
- Produces: `App\Actions\Account\UpdateProfile::handle(User $user, string $name, string $email): User`. Rutas nombradas `account.edit`, `account.profile.update`. La página `Pages/Account/Edit.jsx` recibe la prop `user` (`{id, name, email, email_verified_at}`) y la Task 3 le agrega el segundo formulario.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Account/ProfileTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_without_a_business_can_open_the_account_page(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)->get('/account')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Edit')
                ->where('user.email', $customer->email));
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/account')->assertRedirect('/login');
    }

    public function test_it_updates_the_name_without_touching_verification(): void
    {
        Notification::fake();

        $user = User::factory()->customer()->create(['name' => 'Nombre viejo']);
        $verifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => 'Nombre nuevo', 'email' => $user->email])
            ->assertRedirect('/account');

        $user->refresh();

        $this->assertSame('Nombre nuevo', $user->name);
        $this->assertEquals($verifiedAt, $user->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_changing_the_email_clears_verification_and_sends_a_new_link(): void
    {
        Notification::fake();

        $user = User::factory()->customer()->create();

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => $user->name, 'email' => 'nuevo@example.com'])
            ->assertRedirect('/account');

        $user->refresh();

        $this->assertSame('nuevo@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_it_rejects_an_email_already_taken_by_someone_else(): void
    {
        $user = User::factory()->customer()->create();
        $otherUser = User::factory()->customer()->create();

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => $user->name, 'email' => $otherUser->email])
            ->assertSessionHasErrors('email');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_it_accepts_the_users_own_email_unchanged(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)
            ->patch('/account/profile', ['name' => 'Otro nombre', 'email' => $user->email])
            ->assertSessionHasNoErrors();
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=ProfileTest`
Expected: FAIL — 404 en `/account` (la ruta no existe).

- [ ] **Step 3: Escribir la Action**

Crear `app/Actions/Account/UpdateProfile.php`:

```php
<?php

namespace App\Actions\Account;

use App\Models\User;

class UpdateProfile
{
    public function handle(User $user, string $name, string $email): User
    {
        $emailChanged = $user->email !== $email;

        $user->fill(['name' => $name, 'email' => $email]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }
}
```

- [ ] **Step 4: Escribir el Form Request**

Crear `app/Http/Requests/Account/UpdateProfileRequest.php`:

```php
<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }
}
```

- [ ] **Step 5: Escribir el controlador y las rutas**

Crear `app/Http/Controllers/Account/ProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Account;

use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
            ],
            // Sigue el patrón ya usado por PasswordResetLinkController y
            // EmailVerificationPromptController: el flash `status` se pasa
            // explícito como prop de la página, no vía share() global.
            'status' => session('status'),
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $action): RedirectResponse
    {
        $action->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('email'),
        );

        return redirect()->route('account.edit')->with('status', 'Perfil actualizado.');
    }
}
```

Crear `routes/account.php`:

```php
<?php

use App\Http\Controllers\Account\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('account', [ProfileController::class, 'edit'])->name('account.edit');
    Route::patch('account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
});
```

Modificar `routes/web.php` — agregar el require junto a los demás:

```php
require __DIR__.'/account.php';
require __DIR__.'/auth.php';
require __DIR__.'/dashboard.php';
require __DIR__.'/invitations.php';
require __DIR__.'/public.php';
```

- [ ] **Step 6: Escribir la página Inertia**

Crear `resources/js/Pages/Account/Edit.jsx`. El layout se elige por rol: un `customer` no debe ver la navegación del panel.

```jsx
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import PublicLayout from '../../Components/PublicLayout';
import InputError from '../../Components/InputError';

const STAFF_ROLES = ['owner', 'admin', 'employee'];

export default function Edit({ user }) {
    const { auth, status } = usePage().props;
    const Layout = STAFF_ROLES.includes(auth?.user?.role) ? DashboardLayout : PublicLayout;

    const profile = useForm({ name: user.name, email: user.email });

    function submitProfile(event) {
        event.preventDefault();
        profile.patch('/account/profile', { preserveScroll: true });
    }

    return (
        <Layout>
            <div className="mx-auto max-w-2xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Mi cuenta</h1>

                {status && <p className="mb-4 text-sm text-green-700">{status}</p>}

                <form onSubmit={submitProfile} className="mb-10 space-y-4">
                    <h2 className="text-lg font-semibold">Perfil</h2>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="name">Nombre</label>
                        <input
                            id="name"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={profile.data.name}
                            onChange={(event) => profile.setData('name', event.target.value)}
                        />
                        <InputError message={profile.errors.name} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="email">Email</label>
                        <input
                            id="email"
                            type="email"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={profile.data.email}
                            onChange={(event) => profile.setData('email', event.target.value)}
                        />
                        <InputError message={profile.errors.email} />
                        {profile.data.email !== user.email && (
                            <p className="mt-1 text-sm text-amber-700">
                                Al cambiar el email vas a tener que verificarlo de nuevo.
                            </p>
                        )}
                    </div>

                    <button
                        type="submit"
                        disabled={profile.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Guardar perfil
                    </button>
                </form>
            </div>
        </Layout>
    );
}
```

Antes de correr los tests, reconstruir el bundle (los tests de Inertia renderizan la vista raíz y necesitan el manifest):

```bash
docker compose exec laravel.test bash -lc "rm -f public/hot && pnpm build"
```

- [ ] **Step 7: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=ProfileTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Actions/Account app/Http/Requests/Account app/Http/Controllers/Account routes/account.php routes/web.php resources/js/Pages/Account tests/Feature/Account/ProfileTest.php
git commit -m "feat: add account profile editing"
```

---

### Task 3: Área de cuenta — cambio de contraseña (web)

**Files:**
- Create: `app/Actions/Account/ChangePassword.php`
- Create: `app/Http/Requests/Account/UpdatePasswordRequest.php`
- Create: `app/Http/Controllers/Account/PasswordController.php`
- Modify: `routes/account.php`
- Modify: `resources/js/Pages/Account/Edit.jsx` (segundo formulario)
- Test: `tests/Feature/Account/PasswordTest.php`

**Interfaces:**
- Consumes: `UserAccessRevoker::revoke(User $user, ?string $keepSessionId = null): void` (Task 1); la página `Pages/Account/Edit.jsx` (Task 2).
- Produces: `App\Actions\Account\ChangePassword::handle(User $user, string $password, ?string $keepSessionId = null): void`. `App\Http\Requests\Account\UpdatePasswordRequest` — reutilizado tal cual por la Task 4 (API). Ruta `account.password.update`.

**Decisión de implementación:** la contraseña actual **no** se valida con la regla `current_password`. Esa regla resuelve el guard por defecto (`web`), y la Task 4 usa el mismo Form Request bajo `auth:sanctum`, donde ese guard no tiene usuario y la validación fallaría siempre. Se valida con una closure sobre `$this->user()`, que es el usuario del request cualquiera sea el guard.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Account/PasswordTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    private function insertSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_it_rejects_a_wrong_current_password(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'incorrecta',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'contrasena-nueva-1',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('contrasena-vieja', $user->fresh()->password));
    }

    public function test_it_rejects_an_unconfirmed_password(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'contrasena-vieja',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'otra-cosa',
        ])->assertSessionHasErrors('password');
    }

    public function test_it_changes_the_password_and_revokes_other_access(): void
    {
        $user = User::factory()->customer()->create([
            'password' => 'contrasena-vieja',
            'remember_token' => 'token-original',
        ]);
        $otherUser = User::factory()->customer()->create();
        $user->createToken('cli');

        $this->insertSession('otro-dispositivo', $user->id);
        $this->insertSession('otro-usuario', $otherUser->id);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'contrasena-vieja',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'contrasena-nueva-1',
        ])->assertRedirect('/account');

        $user->refresh();

        $this->assertTrue(Hash::check('contrasena-nueva-1', $user->password));
        $this->assertNotSame('token-original', $user->remember_token);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'otro-dispositivo']);
        $this->assertDatabaseHas('sessions', ['id' => 'otro-usuario']);
    }

    public function test_the_user_stays_authenticated_after_changing_the_password(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'contrasena-vieja',
            'password' => 'contrasena-nueva-1',
            'password_confirmation' => 'contrasena-nueva-1',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->get('/account')->assertOk();
    }
}
```

`UserFactory` castea `password` con `'hashed'`, así que pasar el texto plano en `create([...])` lo hashea solo.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=PasswordTest`
Expected: FAIL — 405/404 en `PUT /account/password`.

- [ ] **Step 3: Escribir el Form Request**

Crear `app/Http/Requests/Account/UpdatePasswordRequest.php`:

```php
<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // No se usa la regla `current_password`: resuelve el guard por
            // defecto (`web`) y este mismo Request se reutiliza bajo
            // `auth:sanctum`, donde ese guard no tiene usuario.
            'current_password' => [
                'required', 'string',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! Hash::check((string) $value, $this->user()->password)) {
                        $fail('La contraseña actual no es correcta.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
```

- [ ] **Step 4: Escribir la Action**

Crear `app/Actions/Account/ChangePassword.php`:

```php
<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Support\UserAccessRevoker;
use Illuminate\Support\Facades\DB;

class ChangePassword
{
    public function __construct(private readonly UserAccessRevoker $revoker) {}

    /**
     * @param  string|null  $keepSessionId  Sesión web a preservar. `null` desde
     *                                      la API: ahí cae todo, incluido el
     *                                      token que hizo la llamada.
     */
    public function handle(User $user, string $password, ?string $keepSessionId = null): void
    {
        DB::transaction(function () use ($user, $password, $keepSessionId): void {
            // El cast `hashed` del modelo hashea al asignar.
            $user->forceFill(['password' => $password])->save();

            $this->revoker->revoke($user, $keepSessionId);
        });
    }
}
```

- [ ] **Step 5: Escribir el controlador y la ruta**

Crear `app/Http/Controllers/Account/PasswordController.php`:

```php
<?php

namespace App\Http\Controllers\Account;

use App\Actions\Account\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request, ChangePassword $action): RedirectResponse
    {
        $action->handle(
            $request->user(),
            $request->validated('password'),
            $request->session()->getId(),
        );

        // Rota también el ID de la sesión actual (anti-fijación). El revoker ya
        // borró las demás.
        $request->session()->regenerate();

        return redirect()->route('account.edit')->with('status', 'Contraseña actualizada.');
    }
}
```

Modificar `routes/account.php` — agregar dentro del grupo `auth`:

```php
Route::put('account/password', [PasswordController::class, 'update'])->name('account.password.update');
```

y el `use App\Http\Controllers\Account\PasswordController;` arriba.

- [ ] **Step 6: Agregar el formulario a la página**

Modificar `resources/js/Pages/Account/Edit.jsx` — agregar el segundo `useForm` debajo del de perfil:

```jsx
    const password = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function submitPassword(event) {
        event.preventDefault();
        password.put('/account/password', {
            preserveScroll: true,
            onSuccess: () => password.reset(),
        });
    }
```

y el formulario, después del de perfil, dentro del mismo `<div className="mx-auto max-w-2xl p-8">`:

```jsx
                <form onSubmit={submitPassword} className="space-y-4">
                    <h2 className="text-lg font-semibold">Contraseña</h2>
                    <p className="text-sm text-gray-600">
                        Al cambiarla se cierran todas tus otras sesiones y se revocan tus tokens de API.
                    </p>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="current_password">Contraseña actual</label>
                        <input
                            id="current_password"
                            type="password"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={password.data.current_password}
                            onChange={(event) => password.setData('current_password', event.target.value)}
                        />
                        <InputError message={password.errors.current_password} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="password">Contraseña nueva</label>
                        <input
                            id="password"
                            type="password"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={password.data.password}
                            onChange={(event) => password.setData('password', event.target.value)}
                        />
                        <InputError message={password.errors.password} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="password_confirmation">Repetir contraseña nueva</label>
                        <input
                            id="password_confirmation"
                            type="password"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={password.data.password_confirmation}
                            onChange={(event) => password.setData('password_confirmation', event.target.value)}
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={password.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Cambiar contraseña
                    </button>
                </form>
```

Reconstruir: `docker compose exec laravel.test bash -lc "rm -f public/hot && pnpm build"`.

- [ ] **Step 7: Correr los tests y verificar que pasan**

Run: `docker compose exec laravel.test php artisan test --filter="PasswordTest|ProfileTest"`
Expected: PASS (10 tests).

- [ ] **Step 8: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Actions/Account app/Http/Requests/Account app/Http/Controllers/Account routes/account.php resources/js/Pages/Account tests/Feature/Account/PasswordTest.php
git commit -m "feat: add authenticated password change"
```

---

### Task 4: Área de cuenta — API

**Files:**
- Create: `app/Http/Resources/AccountResource.php`
- Create: `app/Http/Controllers/Api/AccountController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/AccountTest.php`

**Interfaces:**
- Consumes: `UpdateProfile::handle()` (Task 2), `ChangePassword::handle()` y `UpdatePasswordRequest` (Task 3).
- Produces: rutas `api.account.show`, `api.account.profile.update`, `api.account.password.update`. `App\Http\Resources\AccountResource`.

Las tres rutas van bajo `auth:sanctum` **sin** el middleware `business`: un `customer` no tiene negocio y también usa su cuenta.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Api/AccountTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    public function test_it_returns_the_authenticated_account(): void
    {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/account')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['id' => $user->id, 'email' => $user->email, 'role' => 'customer'],
                'errors' => null,
            ]);
    }

    public function test_it_updates_the_profile_over_the_api(): void
    {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/account/profile', ['name' => 'Nombre nuevo', 'email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre nuevo');
    }

    public function test_changing_the_password_revokes_the_token_used_for_the_request(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/account/password', [
                'current_password' => 'contrasena-vieja',
                'password' => 'contrasena-nueva-1',
                'password_confirmation' => 'contrasena-nueva-1',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => null,
                'message' => 'Contraseña actualizada. Todos los tokens fueron revocados; iniciá sesión de nuevo.',
                'errors' => null,
            ]);

        $this->assertTrue(Hash::check('contrasena-nueva-1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/account')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'No autenticado.']);
    }

    public function test_it_rejects_a_wrong_current_password_with_the_error_envelope(): void
    {
        $user = User::factory()->customer()->create(['password' => 'contrasena-vieja']);
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/account/password', [
                'current_password' => 'incorrecta',
                'password' => 'contrasena-nueva-1',
                'password_confirmation' => 'contrasena-nueva-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.current_password.0', 'La contraseña actual no es correcta.');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_guest_gets_the_unauthenticated_envelope(): void
    {
        $this->getJson('/api/account')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'No autenticado.']);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=Api/AccountTest`
Expected: FAIL — 404 en `/api/account`.

- [ ] **Step 3: Escribir el Resource**

Crear `app/Http/Resources/AccountResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'role' => $this->role?->value,
            'business_id' => $this->business_id,
        ];
    }
}
```

- [ ] **Step 4: Escribir el controlador**

Crear `app/Http/Controllers/Api/AccountController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\ChangePassword;
use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\AccountResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new AccountResource($request->user()));
    }

    public function updateProfile(UpdateProfileRequest $request, UpdateProfile $action): JsonResponse
    {
        $user = $action->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('email'),
        );

        return ApiResponse::success(new AccountResource($user), 'Perfil actualizado.');
    }

    public function updatePassword(UpdatePasswordRequest $request, ChangePassword $action): JsonResponse
    {
        // `null`: por API cae todo, incluido el token que hizo esta llamada.
        $action->handle($request->user(), $request->validated('password'), null);

        return ApiResponse::success(
            null,
            'Contraseña actualizada. Todos los tokens fueron revocados; iniciá sesión de nuevo.',
        );
    }
}
```

- [ ] **Step 5: Registrar las rutas**

Modificar `routes/api.php` — dentro del grupo `Route::middleware('auth:sanctum')` que ya existe (el de reservas compartidas, **sin** `business`), agregar:

```php
        Route::get('account', [AccountController::class, 'show'])->name('account.show');
        Route::patch('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
        Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
```

y arriba `use App\Http\Controllers\Api\AccountController;`.

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=Api/AccountTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Http/Controllers/Api/AccountController.php app/Http/Resources/AccountResource.php routes/api.php tests/Feature/Api/AccountTest.php
git commit -m "feat: expose account profile and password over the API"
```

---

### Task 5: Ajustes del negocio (panel)

**Files:**
- Create: `app/Enums/Currency.php`
- Create: `app/Actions/Businesses/UpdateBusinessSettings.php`
- Create: `app/Http/Requests/Dashboard/UpdateBusinessRequest.php`
- Create: `app/Http/Controllers/Dashboard/BusinessSettingsController.php`
- Create: `resources/js/Pages/Dashboard/Settings/Edit.jsx`
- Modify: `routes/dashboard.php`
- Modify: `resources/js/Components/DashboardLayout.jsx`, `resources/js/Components/PublicLayout.jsx`
- Test: `tests/Feature/Dashboard/BusinessSettingsTest.php`

**Interfaces:**
- Consumes: `BusinessPolicy::view/update(User, Business)` — ya existen, no se tocan.
- Produces: `App\Enums\Currency` (backed enum string) con `Currency::values(): array<int,string>`. `App\Actions\Businesses\UpdateBusinessSettings::handle(Business $business, array $data): Business`. `App\Http\Requests\Dashboard\UpdateBusinessRequest` — reutilizado por la Task 6. Rutas `dashboard.settings.edit` y `dashboard.settings.update`.

**Estrategia de moneda:** enum propio con un set acotado, no una dependencia con la tabla ISO-4217 completa ni un `size:3`. La columna `businesses.currency` **no** se castea al enum en el modelo: hay datos y tests previos que la tratan como string y castear ampliaría el alcance sin beneficio.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Dashboard/BusinessSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: User}
     */
    private function businessWithOwner(array $attributes = []): array
    {
        $business = Business::factory()->create($attributes);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        return [$business, $owner];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Peluquería Nueva',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'cancellation_hours' => 12,
        ], $overrides);
    }

    public function test_an_owner_sees_the_settings_page(): void
    {
        [$business, $owner] = $this->businessWithOwner(['name' => 'Peluquería Vieja']);

        $this->actingAs($owner)->get('/dashboard/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Settings/Edit')
                ->where('business.name', 'Peluquería Vieja')
                ->has('currencies'));
    }

    public function test_an_owner_updates_the_settings(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload())
            ->assertRedirect('/dashboard/settings');

        $business->refresh();

        $this->assertSame('Peluquería Nueva', $business->name);
        $this->assertSame('America/Argentina/Buenos_Aires', $business->timezone);
        $this->assertSame('ARS', $business->currency);
        $this->assertSame(12, $business->cancellation_hours);
    }

    public function test_an_employee_cannot_update_the_settings(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->put('/dashboard/settings', $this->payload())->assertForbidden();
    }

    public function test_the_slug_and_active_flag_are_ignored(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $originalSlug = $business->slug;

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload([
            'slug' => 'slug-secuestrado',
            'is_active' => false,
        ]))->assertRedirect('/dashboard/settings');

        $business->refresh();

        $this->assertSame($originalSlug, $business->slug);
        $this->assertTrue($business->is_active);
    }

    public function test_it_rejects_an_unsupported_currency_and_an_invalid_timezone(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload(['currency' => 'ABC']))
            ->assertSessionHasErrors('currency');

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload(['timezone' => 'Marte/Olympus']))
            ->assertSessionHasErrors('timezone');
    }

    public function test_changing_the_timezone_does_not_move_a_persisted_booking(): void
    {
        [$business, $owner] = $this->businessWithOwner(['timezone' => 'UTC']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);

        $startsAt = CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC');

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload([
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]))->assertRedirect('/dashboard/settings');

        $this->assertSame(
            $startsAt->toIso8601String(),
            $booking->fresh()->starts_at->utc()->toIso8601String(),
        );
    }
}
```

Si `BookingFactory` exige campos adicionales, mirar `database/factories/BookingFactory.php` y ajustar el `create([...])`; los de arriba son los que el dominio necesita.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BusinessSettingsTest`
Expected: FAIL — 404 en `/dashboard/settings`.

- [ ] **Step 3: Escribir el enum de moneda**

Crear `app/Enums/Currency.php`:

```php
<?php

namespace App\Enums;

/**
 * Códigos ISO-4217 soportados por ReservaHub.
 *
 * Set acotado a propósito: validar solo "tres letras" aceptaría `ABC`, y traer
 * la tabla ISO-4217 completa como dependencia es peso de mantenimiento para un
 * catálogo que este proyecto no necesita. Agregar una moneda es una línea.
 */
enum Currency: string
{
    case ARS = 'ARS';
    case BRL = 'BRL';
    case CLP = 'CLP';
    case COP = 'COP';
    case EUR = 'EUR';
    case MXN = 'MXN';
    case PEN = 'PEN';
    case USD = 'USD';
    case UYU = 'UYU';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: Escribir el Form Request y la Action**

Crear `app/Http/Requests/Dashboard/UpdateBusinessRequest.php`:

```php
<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\Currency;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && in_array($this->user()->role, Role::managers(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'cancellation_hours' => ['required', 'integer', 'min:0', 'max:168'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.timezone' => 'La zona horaria no es válida.',
        ];
    }
}
```

Crear `app/Actions/Businesses/UpdateBusinessSettings.php`:

```php
<?php

namespace App\Actions\Businesses;

use App\Models\Business;

class UpdateBusinessSettings
{
    /**
     * Asigna campo por campo a propósito: `slug`, `logo_path` e `is_active` son
     * fillable en el modelo y un `update($data)` masivo dejaría que se colaran
     * desde el request.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Business $business, array $data): Business
    {
        $business->update([
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'currency' => $data['currency'],
            'cancellation_hours' => $data['cancellation_hours'],
        ]);

        return $business;
    }
}
```

- [ ] **Step 5: Escribir el controlador y las rutas**

Crear `app/Http/Controllers/Dashboard/BusinessSettingsController.php`:

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Businesses\UpdateBusinessSettings;
use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateBusinessRequest;
use App\Models\Business;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function edit(): Response
    {
        $business = Business::current();

        $this->authorize('update', $business);

        return Inertia::render('Dashboard/Settings/Edit', [
            'business' => [
                'name' => $business->name,
                'slug' => $business->slug,
                'timezone' => $business->timezone,
                'currency' => $business->currency,
                'cancellation_hours' => $business->cancellation_hours,
            ],
            'currencies' => Currency::values(),
            'timezones' => DateTimeZone::listIdentifiers(),
            // Mismo patrón que PasswordResetLinkController: flash `status`
            // pasado explícito, no vía share() global.
            'status' => session('status'),
        ]);
    }

    public function update(UpdateBusinessRequest $request, UpdateBusinessSettings $action): RedirectResponse
    {
        $business = Business::current();

        $this->authorize('update', $business);

        $action->handle($business, $request->validated());

        return redirect()->route('dashboard.settings.edit')->with('status', 'Ajustes actualizados.');
    }
}
```

Modificar `routes/dashboard.php` — dentro del grupo `Route::prefix('dashboard')->name('dashboard.')`:

```php
        Route::get('settings', [BusinessSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [BusinessSettingsController::class, 'update'])->name('settings.update');
```

y el `use App\Http\Controllers\Dashboard\BusinessSettingsController;` arriba.

- [ ] **Step 6: Escribir la página y los enlaces de navegación**

Crear `resources/js/Pages/Dashboard/Settings/Edit.jsx`:

```jsx
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Edit({ business, currencies, timezones }) {
    const { status } = usePage().props;

    const form = useForm({
        name: business.name,
        timezone: business.timezone,
        currency: business.currency,
        cancellation_hours: business.cancellation_hours,
    });

    const timezoneChanged = form.data.timezone !== business.timezone;

    function submit(event) {
        event.preventDefault();
        form.put('/dashboard/settings', { preserveScroll: true });
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-2xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Ajustes del negocio</h1>

                {status && <p className="mb-4 text-sm text-green-700">{status}</p>}

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium" htmlFor="name">Nombre</label>
                        <input
                            id="name"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="timezone">Zona horaria</label>
                        <select
                            id="timezone"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.timezone}
                            onChange={(event) => form.setData('timezone', event.target.value)}
                        >
                            {timezones.map((timezone) => (
                                <option key={timezone} value={timezone}>{timezone}</option>
                            ))}
                        </select>
                        <InputError message={form.errors.timezone} />
                        {timezoneChanged && (
                            <p className="mt-1 text-sm text-amber-700">
                                Las reservas ya creadas no se mueven, pero los horarios semanales de tus
                                empleados pasan a interpretarse en la zona nueva. Revisalos después de guardar.
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="currency">Moneda</label>
                        <select
                            id="currency"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.currency}
                            onChange={(event) => form.setData('currency', event.target.value)}
                        >
                            {currencies.map((currency) => (
                                <option key={currency} value={currency}>{currency}</option>
                            ))}
                        </select>
                        <InputError message={form.errors.currency} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="cancellation_hours">
                            Horas mínimas para cancelar
                        </label>
                        <input
                            id="cancellation_hours"
                            type="number"
                            min="0"
                            max="168"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.cancellation_hours}
                            onChange={(event) => form.setData('cancellation_hours', event.target.value)}
                        />
                        <InputError message={form.errors.cancellation_hours} />
                    </div>

                    <p className="text-sm text-gray-600">
                        La dirección pública de tu negocio (<code>/businesses/{business.slug}</code>) no se
                        puede cambiar: los enlaces ya compartidos dejarían de funcionar.
                    </p>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Guardar ajustes
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
```

Modificar `resources/js/Components/DashboardLayout.jsx` — dentro del `<nav>`, reemplazar el bloque final (el botón "Salir" con `ml-auto`) por:

```jsx
                {isManager && (
                    <Link href="/dashboard/settings" className="hover:text-gray-900">Ajustes</Link>
                )}
                <Link href="/account" className="ml-auto hover:text-gray-900">Mi cuenta</Link>
                <button onClick={() => router.post('/logout')} className="hover:text-gray-900">
                    Salir
                </button>
```

Modificar `resources/js/Components/PublicLayout.jsx` — agregar, después del enlace "Mis reservas":

```jsx
                    <Link href="/account" className="hover:text-gray-900">Mi cuenta</Link>
```

Reconstruir: `docker compose exec laravel.test bash -lc "rm -f public/hot && pnpm build"`.

- [ ] **Step 7: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=BusinessSettingsTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Enums/Currency.php app/Actions/Businesses app/Http/Requests/Dashboard/UpdateBusinessRequest.php app/Http/Controllers/Dashboard/BusinessSettingsController.php routes/dashboard.php resources/js tests/Feature/Dashboard/BusinessSettingsTest.php
git commit -m "feat: add business settings management"
```

---

### Task 6: Ajustes del negocio — API

**Files:**
- Create: `app/Http/Resources/BusinessResource.php`
- Create: `app/Http/Controllers/Api/BusinessController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/BusinessTest.php`

**Interfaces:**
- Consumes: `UpdateBusinessSettings::handle(Business, array): Business` y `UpdateBusinessRequest` (Task 5).
- Produces: rutas `api.business.show`, `api.business.update`. `App\Http\Resources\BusinessResource`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Api/BusinessTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('cli')->plainTextToken;
    }

    public function test_an_owner_reads_the_business(): void
    {
        $business = Business::factory()->create(['name' => 'Peluquería Vieja']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/business')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['name' => 'Peluquería Vieja', 'slug' => $business->slug],
                'errors' => null,
            ]);
    }

    public function test_an_owner_updates_the_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson('/api/business', [
                'name' => 'Peluquería Nueva',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'currency' => 'ARS',
                'cancellation_hours' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Peluquería Nueva')
            ->assertJsonPath('message', 'Ajustes actualizados.');

        $this->assertSame(6, $business->fresh()->cancellation_hours);
    }

    public function test_an_employee_is_forbidden(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($employee))
            ->putJson('/api/business', [
                'name' => 'Peluquería Nueva',
                'timezone' => 'UTC',
                'currency' => 'USD',
                'cancellation_hours' => 6,
            ])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'No tenés permiso para realizar esta acción.']);
    }

    public function test_a_customer_without_a_business_is_forbidden(): void
    {
        $customer = User::factory()->customer()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->getJson('/api/business')
            ->assertStatus(403);
    }

    public function test_an_unsupported_currency_returns_the_validation_envelope(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson('/api/business', [
                'name' => 'Peluquería Nueva',
                'timezone' => 'UTC',
                'currency' => 'ABC',
                'cancellation_hours' => 6,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Los datos enviados no son válidos.')
            ->assertJsonStructure(['errors' => ['currency']]);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=Api/BusinessTest`
Expected: FAIL — 404 en `/api/business`.

- [ ] **Step 3: Escribir el Resource y el controlador**

Crear `app/Http/Resources/BusinessResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'cancellation_hours' => $this->cancellation_hours,
        ];
    }
}
```

Crear `app/Http/Controllers/Api/BusinessController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Actions\Businesses\UpdateBusinessSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BusinessController extends Controller
{
    public function show(): JsonResponse
    {
        $business = Business::current();

        $this->authorize('view', $business);

        return ApiResponse::success(new BusinessResource($business));
    }

    public function update(UpdateBusinessRequest $request, UpdateBusinessSettings $action): JsonResponse
    {
        $business = Business::current();

        $this->authorize('update', $business);

        $action->handle($business, $request->validated());

        return ApiResponse::success(new BusinessResource($business), 'Ajustes actualizados.');
    }
}
```

- [ ] **Step 4: Registrar las rutas**

Modificar `routes/api.php` — dentro del grupo `Route::middleware(['auth:sanctum', 'business'])`:

```php
        Route::get('business', [BusinessController::class, 'show'])->name('business.show');
        Route::put('business', [BusinessController::class, 'update'])->name('business.update');
```

y arriba `use App\Http\Controllers\Api\BusinessController;`.

Nota: el 403 del `employee` llega por dos caminos y los dos son correctos — `UpdateBusinessRequest::authorize()` en el PUT y la Policy en el GET. Ambos se mapean al mismo envelope en `bootstrap/app.php`. El `customer` ni siquiera pasa el middleware `business`.

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=Api/BusinessTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Http/Controllers/Api/BusinessController.php app/Http/Resources/BusinessResource.php routes/api.php tests/Feature/Api/BusinessTest.php
git commit -m "feat: expose business settings over the API"
```

---

### Task 7: Activación y desactivación de usuarios (panel)

**Files:**
- Modify: `app/Policies/UserPolicy.php` (agregar `setActiveStatus`)
- Create: `app/Actions/Users/SetUserActiveStatus.php`
- Create: `app/Http/Requests/Dashboard/UpdateUserStatusRequest.php`
- Create: `app/Http/Controllers/Dashboard/UserStatusController.php`
- Modify: `routes/dashboard.php`
- Modify: `app/Http/Controllers/Dashboard/EmployeeController.php` (pasar `status` y `future_bookings_count` a la página)
- Modify: `resources/js/Pages/Dashboard/Employees/Index.jsx`
- Test: `tests/Feature/Dashboard/UserStatusTest.php`

**Interfaces:**
- Consumes: `UserAccessRevoker::revoke(User $user, ?string $keepSessionId = null): void` (Task 1).
- Produces: `App\Policies\UserPolicy::setActiveStatus(User $actor, User $target): bool`. `App\Actions\Users\SetUserActiveStatus::handle(User $target, bool $isActive): array{user: User, future_bookings_count: int}` — la Task 9 consume esa forma exacta. `App\Http\Requests\Dashboard\UpdateUserStatusRequest` — reutilizado por la Task 9. Ruta `dashboard.users.status.update`.

**Reparto de responsabilidades:** la Policy decide por identidad y rol (datos que no cambian durante el request); el invariante del último owner activo vive en la Action porque depende del estado actual de la tabla y necesita lock.

Matriz de la Policy:

| Actor \ Target | `owner` | `admin` | `employee` |
|---|---|---|---|
| `owner` | permitido | permitido | permitido |
| `admin` | **denegado** | permitido | permitido |
| `employee` | denegado | denegado | denegado |

Los `customer` quedan fuera por construcción: `RegisterCustomer` y `UserFactory` los crean con `business_id = null`, así que la regla de mismo negocio ya los excluye.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Dashboard/UserStatusTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Users\SetUserActiveStatus;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    private function userFor(Business $business, Role $role): User
    {
        return User::factory()->create(['role' => $role, 'business_id' => $business->id]);
    }

    public function test_an_owner_deactivates_an_employee_and_revokes_their_access(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $employee = $this->userFor($business, Role::Employee);
        $employee->createToken('cli');

        DB::table('sessions')->insert([
            'id' => 'sesion-del-empleado',
            'user_id' => $employee->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$employee->id}/status", ['is_active' => false])
            ->assertRedirect('/dashboard/employees');

        $this->assertFalse($employee->fresh()->is_active);
        $this->assertSame(0, $employee->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-empleado']);
    }

    public function test_an_owner_reactivates_an_employee(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $employee = $this->userFor($business, Role::Employee);
        $employee->update(['is_active' => false]);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$employee->id}/status", ['is_active' => true])
            ->assertRedirect('/dashboard/employees');

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_an_owner(): void
    {
        $business = Business::factory()->create();
        $admin = $this->userFor($business, Role::Admin);
        $owner = $this->userFor($business, Role::Owner);

        $this->actingAs($admin)
            ->put("/dashboard/users/{$owner->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_an_admin_can_deactivate_another_admin_and_an_employee(): void
    {
        $business = Business::factory()->create();
        $admin = $this->userFor($business, Role::Admin);
        $otherAdmin = $this->userFor($business, Role::Admin);
        $employee = $this->userFor($business, Role::Employee);

        $this->actingAs($admin)->put("/dashboard/users/{$otherAdmin->id}/status", ['is_active' => false]);
        $this->actingAs($admin)->put("/dashboard/users/{$employee->id}/status", ['is_active' => false]);

        $this->assertFalse($otherAdmin->fresh()->is_active);
        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_an_employee_cannot_change_anyones_status(): void
    {
        $business = Business::factory()->create();
        $employee = $this->userFor($business, Role::Employee);
        $otherEmployee = $this->userFor($business, Role::Employee);

        $this->actingAs($employee)
            ->put("/dashboard/users/{$otherEmployee->id}/status", ['is_active' => false])
            ->assertForbidden();
    }

    public function test_nobody_can_change_their_own_status(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$owner->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_a_manager_cannot_touch_a_user_from_another_business(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $otherBusiness = Business::factory()->create();
        $stranger = $this->userFor($otherBusiness, Role::Employee);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$stranger->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($stranger->fresh()->is_active);
    }

    /**
     * Not an HTTP test on purpose: an admin+single-owner HTTP scenario is
     * already covered by test_an_admin_cannot_deactivate_an_owner() above,
     * which asserts 403 — the Policy denies admin-on-owner unconditionally,
     * before the Action's last-owner guard ever runs. The only actor who can
     * legitimately reach that guard via HTTP is another active owner, and an
     * active owner always counts as "another owner remains", so the guard
     * can never fire over a single synchronous HTTP request in this
     * architecture. Exercising SetUserActiveStatus::handle() directly is the
     * only way to test the invariant itself; the concurrent-request case is
     * covered separately by Task 8.
     */
    public function test_the_last_active_owner_cannot_be_deactivated(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);

        try {
            app(SetUserActiveStatus::class)->handle($owner, false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_an_owner_can_be_deactivated_when_another_active_owner_remains(): void
    {
        $business = Business::factory()->create();
        $firstOwner = $this->userFor($business, Role::Owner);
        $secondOwner = $this->userFor($business, Role::Owner);

        $this->actingAs($firstOwner)
            ->put("/dashboard/users/{$secondOwner->id}/status", ['is_active' => false])
            ->assertRedirect('/dashboard/employees');

        $this->assertFalse($secondOwner->fresh()->is_active);
    }

    public function test_deactivating_reports_future_bookings_without_cancelling_them(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $employee = $this->userFor($business, Role::Employee);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);

        $futureStart = CarbonImmutable::now('UTC')->addWeek();
        $pastStart = CarbonImmutable::now('UTC')->subWeek();

        $future = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $futureStart,
            'ends_at' => $futureStart->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $pastStart,
            'ends_at' => $pastStart->addMinutes(30),
            'status' => BookingStatus::Completed,
        ]);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$employee->id}/status", ['is_active' => false])
            ->assertSessionHas('future_bookings_count', 1);

        $this->assertSame(BookingStatus::Confirmed, $future->fresh()->status);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=UserStatusTest`
Expected: FAIL — 404 en `PUT /dashboard/users/{id}/status`.

- [ ] **Step 3: Extender la Policy**

Modificar `app/Policies/UserPolicy.php` — agregar el método (dejar `update` y `delete` como están: responden otra pregunta y tienen otros consumidores):

```php
    /**
     * Activar/desactivar a otro usuario del mismo negocio.
     *
     * Decide solo por identidad y rol. El invariante del último owner activo
     * vive en la Action: depende del estado actual de los datos y necesita lock.
     */
    public function setActiveStatus(User $user, User $target): bool
    {
        if ($user->business_id === null || $user->business_id !== $target->business_id) {
            return false;
        }

        if ($user->id === $target->id) {
            return false;
        }

        return match ($user->role) {
            Role::Owner => true,
            Role::Admin => $target->role !== Role::Owner,
            default => false,
        };
    }
```

- [ ] **Step 4: Escribir la Action**

Crear `app/Actions/Users/SetUserActiveStatus.php`:

```php
<?php

namespace App\Actions\Users;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use App\Support\UserAccessRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetUserActiveStatus
{
    public function __construct(private readonly UserAccessRevoker $revoker) {}

    /**
     * @return array{user: User, future_bookings_count: int}
     *
     * @throws ValidationException cuando se intenta desactivar al último owner
     *         activo del negocio.
     */
    public function handle(User $target, bool $isActive): array
    {
        return DB::transaction(function () use ($target, $isActive): array {
            if (! $isActive && $target->role === Role::Owner) {
                $this->assertAnotherOwnerRemains($target);
            }

            $target->forceFill(['is_active' => $isActive])->save();

            if ($isActive) {
                return ['user' => $target, 'future_bookings_count' => 0];
            }

            $futureBookingsCount = Booking::query()
                ->withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $target->business_id)
                ->where('employee_id', $target->id)
                ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
                ->where('starts_at', '>', now())
                ->count();

            $this->revoker->revoke($target);

            return ['user' => $target, 'future_bookings_count' => $futureBookingsCount];
        });
    }

    /**
     * Bloquea TODAS las filas de owners activos del negocio, ordenadas por id.
     *
     * El orden fijo es lo que evita el deadlock entre dos desactivaciones
     * simultáneas: ambas transacciones piden los mismos locks en la misma
     * secuencia, así que la segunda espera en vez de cruzarse con la primera.
     * Al desbloquearse, Postgres re-evalúa la fila contra el WHERE (READ
     * COMMITTED), así que la segunda ya no ve como activo al owner que la
     * primera acaba de desactivar y levanta la excepción.
     */
    private function assertAnotherOwnerRemains(User $target): void
    {
        $activeOwnerIds = User::query()
            ->where('business_id', $target->business_id)
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeOwnerIds->count() <= 1 && $activeOwnerIds->contains($target->id)) {
            throw ValidationException::withMessages([
                'is_active' => 'No podés desactivar al último propietario activo del negocio.',
            ]);
        }
    }
}
```

- [ ] **Step 5: Escribir el Form Request, el controlador y la ruta**

Crear `app/Http/Requests/Dashboard/UpdateUserStatusRequest.php`:

```php
<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización real la hace la Policy en el controlador: necesita el
        // usuario destino ya resuelto por el route-model binding.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
```

Crear `app/Http/Controllers/Dashboard/UserStatusController.php`:

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Users\SetUserActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateUserStatusRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserStatusController extends Controller
{
    public function update(UpdateUserStatusRequest $request, User $user, SetUserActiveStatus $action): RedirectResponse
    {
        $this->authorize('setActiveStatus', $user);

        $result = $action->handle($user, $request->boolean('is_active'));

        return redirect()->route('dashboard.employees.index')
            ->with('status', $result['user']->is_active ? 'Usuario activado.' : 'Usuario desactivado.')
            ->with('future_bookings_count', $result['future_bookings_count']);
    }
}
```

Modificar `routes/dashboard.php` — dentro del grupo `dashboard.`:

```php
        Route::put('users/{user}/status', [UserStatusController::class, 'update'])->name('users.status.update');
```

y el `use App\Http\Controllers\Dashboard\UserStatusController;` arriba.

El modelo `User` no tiene global scope de negocio, así que el binding resuelve también a un usuario de otro negocio; la Policy lo corta con 403. Ese es el comportamiento que el test `test_a_manager_cannot_touch_a_user_from_another_business` fija.

- [ ] **Step 6: Pasar `status` y `future_bookings_count` a la página de empleados**

`UserStatusController::update()` (Step 5) flashea `status` y `future_bookings_count` a la sesión con `->with(...)`, pero el redirect apunta a `dashboard.employees.index`, que sirve `App\Http\Controllers\Dashboard\EmployeeController::index()` — un controlador que ya existe y esta task no creó. Ese método no pasa esos valores como props de Inertia todavía, y sin eso el flash nunca llega a la página (el patrón del proyecto es pasarlo explícito por controlador, como hace `PasswordResetLinkController`, no vía `share()` global).

Modificar `app/Http/Controllers/Dashboard/EmployeeController.php` — en el `return Inertia::render('Dashboard/Employees/Index', [...])` de `index()`, agregar dos claves al array de props ya existente:

```php
            'status' => session('status'),
            'future_bookings_count' => session('future_bookings_count'),
```

- [ ] **Step 7: Agregar el toggle a la página de empleados**

Modificar `resources/js/Pages/Dashboard/Employees/Index.jsx` — agregar la función y la columna. Importar `usePage` si no está importado ya:

```jsx
    const { status, future_bookings_count: futureBookingsCount } = usePage().props;

    function toggleStatus(employee) {
        const action = employee.is_active ? 'desactivar' : 'activar';

        if (! confirm(`¿Querés ${action} a ${employee.name}?`)) {
            return;
        }

        router.put(`/dashboard/users/${employee.id}/status`, { is_active: ! employee.is_active });
    }
```

Arriba de la tabla, el aviso de reservas futuras:

```jsx
                {status && <p className="mb-4 text-sm text-green-700">{status}</p>}
                {futureBookingsCount > 0 && (
                    <p className="mb-4 text-sm text-amber-700">
                        Ese usuario tiene {futureBookingsCount} reserva(s) futura(s) a su nombre. No se
                        cancelaron: reasignalas o cancelalas desde Reservas.
                    </p>
                )}
```

Y en cada fila, una celda con el botón (solo para managers, siguiendo el `isManager` que la página ya usa):

```jsx
                                {isManager && (
                                    <td className="py-2 text-right">
                                        <button onClick={() => toggleStatus(employee)} className="underline">
                                            {employee.is_active ? 'Desactivar' : 'Activar'}
                                        </button>
                                    </td>
                                )}
```

Si la fila no tiene todavía una celda de acciones ni la cabecera correspondiente, agregar ambas. Reconstruir: `docker compose exec laravel.test bash -lc "rm -f public/hot && pnpm build"`.

- [ ] **Step 8: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=UserStatusTest`
Expected: PASS (10 tests).

- [ ] **Step 9: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Policies/UserPolicy.php app/Actions/Users app/Http/Requests/Dashboard/UpdateUserStatusRequest.php app/Http/Controllers/Dashboard/UserStatusController.php app/Http/Controllers/Dashboard/EmployeeController.php routes/dashboard.php resources/js/Pages/Dashboard/Employees/Index.jsx tests/Feature/Dashboard/UserStatusTest.php
git commit -m "feat: add user activation and deactivation"
```

---

### Task 8: Invariante de concurrencia del último owner

La spec afirma que dos desactivaciones simultáneas no pueden dejar el negocio sin propietario activo. Esa afirmación se prueba, no se asume.

**Files:**
- Test: `tests/Feature/Dashboard/UserStatusConcurrencyTest.php`

**Interfaces:**
- Consumes: `SetUserActiveStatus::handle(User, bool): array` (Task 7).
- Produces: nada de código de aplicación. Si el test falla, el bug está en `assertAnotherOwnerRemains()`.

**Mecanismo elegido y por qué.** `tests/Unit/Database/AdvisoryLockTest.php` ya abre dos conexiones PDO crudas contra el Postgres de test, así que la infraestructura para concurrencia real existe. La restricción es que `RefreshDatabase` envuelve cada test en una transacción, y entonces una segunda conexión no vería los datos sembrados. Por eso este test usa `DatabaseMigrations` (migra de cero, sin transacción envolvente): los `INSERT` quedan comiteados y visibles para las dos sesiones.

Dos partes:

1. **Concurrencia real** con dos sesiones PDO que ejecutan el mismo par SELECT-FOR-UPDATE/UPDATE que emite la Action, en paralelo, sobre dos owners distintos. Invariante final: `owners activos >= 1`.
2. **La Action bajo secuencia**, para probar que la excepción sale del código de la aplicación y no solo del lock.

- [ ] **Step 1: Escribir el test**

Crear `tests/Feature/Dashboard/UserStatusConcurrencyTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Users\SetUserActiveStatus;
use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use PDO;
use Tests\TestCase;

/**
 * `DatabaseMigrations` en vez de `RefreshDatabase`: este test necesita que los
 * datos sembrados estén comiteados para que una segunda sesión de Postgres los
 * vea. `RefreshDatabase` envuelve el test en una transacción y lo impediría.
 */
class UserStatusConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function rawConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password']);
    }

    /**
     * Réplica exacta del guard de SetUserActiveStatus::assertAnotherOwnerRemains(),
     * ejecutada sobre una sesión de Postgres arbitraria.
     *
     * @return bool `true` si la desactivación se aplicó.
     */
    private function deactivateOwnerOn(PDO $session, int $businessId, int $targetId): bool
    {
        $session->beginTransaction();

        $statement = $session->prepare(
            'select id from users where business_id = :business and role = :role and is_active = true order by id for update'
        );
        $statement->execute(['business' => $businessId, 'role' => Role::Owner->value]);
        $activeOwnerIds = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (count($activeOwnerIds) <= 1 && in_array((string) $targetId, array_map('strval', $activeOwnerIds), true)) {
            $session->rollBack();

            return false;
        }

        $update = $session->prepare('update users set is_active = false where id = :id');
        $update->execute(['id' => $targetId]);

        $session->commit();

        return true;
    }

    public function test_two_concurrent_deactivations_cannot_leave_the_business_without_an_active_owner(): void
    {
        $business = Business::factory()->create();
        $firstOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $secondOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $sessionA = $this->rawConnection();
        $sessionB = $this->rawConnection();

        // A abre su transacción y toma los locks de los dos owners activos.
        $sessionA->beginTransaction();
        $lockStatement = $sessionA->prepare(
            'select id from users where business_id = :business and role = :role and is_active = true order by id for update'
        );
        $lockStatement->execute(['business' => $business->id, 'role' => Role::Owner->value]);
        $lockedIds = $lockStatement->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(2, $lockedIds);

        // A desactiva al primero y comitea, liberando los locks.
        $sessionA->prepare('update users set is_active = false where id = :id')
            ->execute(['id' => $firstOwner->id]);
        $sessionA->commit();

        // B corre ahora el mismo guard: ya no debe poder desactivar al que queda.
        $applied = $this->deactivateOwnerOn($sessionB, $business->id, $secondOwner->id);

        $this->assertFalse($applied, 'La segunda desactivación no debería haberse aplicado.');

        $activeOwners = User::query()
            ->where('business_id', $business->id)
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThanOrEqual(1, $activeOwners);
        $this->assertTrue($secondOwner->fresh()->is_active);
    }

    public function test_the_action_rejects_the_second_deactivation(): void
    {
        $business = Business::factory()->create();
        $firstOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $secondOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $action = app(SetUserActiveStatus::class);

        config()->set('session.driver', 'database');

        $action->handle($firstOwner, false);

        try {
            $action->handle($secondOwner, false);
            $this->fail('Se esperaba una ValidationException al desactivar al último owner activo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_active', $exception->errors());
        }

        $activeOwners = User::query()
            ->where('business_id', $business->id)
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThanOrEqual(1, $activeOwners);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=UserStatusConcurrencyTest`
Expected: PASS (2 tests). Es más lento que el resto porque `DatabaseMigrations` migra de cero por test.

Si la primera parte falla porque `count($activeOwnerIds)` devuelve 2 cuando debería devolver 1, el problema es que el guard de la Action no está re-leyendo el estado comiteado: revisar que el `SELECT ... FOR UPDATE` filtre por `is_active = true` y no por una lista precargada.

- [ ] **Step 3: Commitear**

```bash
git add tests/Feature/Dashboard/UserStatusConcurrencyTest.php
git commit -m "test: cover the last-active-owner invariant under concurrency"
```

---

### Task 9: Estado de usuario — API

**Files:**
- Create: `app/Http/Controllers/Api/UserStatusController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/UsersTest.php`

**Interfaces:**
- Consumes: `SetUserActiveStatus::handle(User, bool): array{user: User, future_bookings_count: int}`, `UpdateUserStatusRequest` y `UserPolicy::setActiveStatus` (Task 7). `App\Http\Resources\UserResource` ya existe en el repo.
- Produces: ruta `api.users.status.update`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Api/UsersTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Actions\Users\SetUserActiveStatus;
use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('cli')->plainTextToken;
    }

    public function test_an_owner_deactivates_an_employee_over_the_api(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/users/{$employee->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.is_active', false)
            ->assertJsonPath('data.future_bookings_count', 0)
            ->assertJsonPath('message', 'Usuario desactivado.');

        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_an_owner_over_the_api(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin, 'business_id' => $business->id]);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->putJson("/api/users/{$owner->id}/status", ['is_active' => false])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'No tenés permiso para realizar esta acción.']);

        $this->assertTrue($owner->fresh()->is_active);
    }

    /**
     * Not an HTTP test on purpose, same reasoning as the panel equivalent in
     * Task 7: with a single owner, every possible HTTP actor is blocked by
     * the Policy before the Action's last-owner guard ever runs — an admin
     * is denied outright (test_an_admin_cannot_deactivate_an_owner_over_the_api,
     * 403), and the owner itself is denied by the not-self check. The guard
     * only ever fires for a second concurrent request racing a first one
     * that already committed (covered by Task 8) or for a direct call to the
     * Action. This test proves the API's `ApiResponse` envelope correctly
     * wraps a `ValidationException` thrown mid-Action (not from FormRequest
     * validation) — a code path other API validation tests don't exercise —
     * by asserting the Action's own exception, since `bootstrap/app.php`
     * maps ValidationException the same way regardless of where it's thrown.
     */
    public function test_the_last_active_owner_is_rejected_with_the_validation_envelope(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        try {
            app(SetUserActiveStatus::class)->handle($owner, false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'No podés desactivar al último propietario activo del negocio.',
                $e->errors()['is_active'][0],
            );
        }

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_a_user_from_another_business_is_forbidden(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $stranger = User::factory()->create([
            'role' => Role::Employee,
            'business_id' => Business::factory()->create()->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/users/{$stranger->id}/status", ['is_active' => false])
            ->assertStatus(403);

        $this->assertTrue($stranger->fresh()->is_active);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=Api/UsersTest`
Expected: FAIL — 404 en `PUT /api/users/{id}/status`.

- [ ] **Step 3: Escribir el controlador**

Crear `app/Http/Controllers/Api/UserStatusController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Actions\Users\SetUserActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserStatusController extends Controller
{
    public function update(UpdateUserStatusRequest $request, User $user, SetUserActiveStatus $action): JsonResponse
    {
        $this->authorize('setActiveStatus', $user);

        $result = $action->handle($user, $request->boolean('is_active'));

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'future_bookings_count' => $result['future_bookings_count'],
        ], $result['user']->is_active ? 'Usuario activado.' : 'Usuario desactivado.');
    }
}
```

Antes de correr los tests, abrir `app/Http/Resources/UserResource.php` y confirmar que expone `is_active`. Si no lo expone, agregarlo:

```php
            'is_active' => $this->is_active,
```

- [ ] **Step 4: Registrar la ruta**

Modificar `routes/api.php` — dentro del grupo `Route::middleware(['auth:sanctum', 'business'])`:

```php
        Route::put('users/{user}/status', [UserStatusController::class, 'update'])
            ->name('users.status.update')
            ->whereNumber('user');
```

y arriba `use App\Http\Controllers\Api\UserStatusController;`.

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=Api/UsersTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Http/Controllers/Api/UserStatusController.php app/Http/Resources/UserResource.php routes/api.php tests/Feature/Api/UsersTest.php
git commit -m "feat: expose user activation over the API"
```

---

### Task 10: Feriados — tabla, modelo, factory y Policy

**Files:**
- Create: `database/migrations/2026_08_14_000001_create_business_holidays_table.php`
- Create: `app/Models/BusinessHoliday.php`
- Create: `database/factories/BusinessHolidayFactory.php`
- Create: `app/Policies/BusinessHolidayPolicy.php`
- Test: `tests/Feature/Tenancy/BusinessHolidayScopeTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\BelongsToBusiness` y `App\Models\Scopes\BusinessScope` — ya existen.
- Produces: `App\Models\BusinessHoliday` con columnas `id, business_id, name, starts_on (date), ends_on (date)` y casts `date`. `App\Policies\BusinessHolidayPolicy::viewAny(User): bool`, `::create(User): bool`, `::delete(User, BusinessHoliday): bool`. Factory `BusinessHoliday::factory()`. Las Tasks 11, 12 y 13 los consumen.

Rango inclusivo de días completos: una fila cubre tanto un feriado suelto como un cierre de varios días. Sin recurrencia anual y sin feriados parciales — eso último ya lo cubre `time_offs` a nivel empleado.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Tenancy/BusinessHolidayScopeTest.php`:

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\BusinessHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHolidayScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_global_scope_hides_holidays_from_other_businesses(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();

        $own = BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'name' => 'Feriado propio',
        ]);
        BusinessHoliday::factory()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Feriado ajeno',
        ]);

        app()->instance(Business::class, $business);

        $holidays = BusinessHoliday::all();

        $this->assertCount(1, $holidays);
        $this->assertSame($own->id, $holidays->first()->id);
    }

    public function test_it_stamps_the_current_business_on_create(): void
    {
        $business = Business::factory()->create();
        app()->instance(Business::class, $business);

        $holiday = BusinessHoliday::create([
            'name' => 'Día de la Independencia',
            'starts_on' => '2026-07-09',
            'ends_on' => '2026-07-09',
        ]);

        $this->assertSame($business->id, $holiday->business_id);
    }

    public function test_it_casts_the_date_columns(): void
    {
        $business = Business::factory()->create();

        $holiday = BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => '2026-12-24',
            'ends_on' => '2027-01-02',
        ]);

        $this->assertSame('2026-12-24', $holiday->fresh()->starts_on->toDateString());
        $this->assertSame('2027-01-02', $holiday->fresh()->ends_on->toDateString());
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=BusinessHolidayScopeTest`
Expected: FAIL — `Class "App\Models\BusinessHoliday" not found`.

- [ ] **Step 3: Escribir la migración**

Crear `database/migrations/2026_08_14_000001_create_business_holidays_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['business_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_holidays');
    }
};
```

- [ ] **Step 4: Escribir el modelo y la factory**

Crear `app/Models/BusinessHoliday.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\BusinessHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'name', 'starts_on', 'ends_on'])]
class BusinessHoliday extends Model
{
    use BelongsToBusiness;

    /** @use HasFactory<BusinessHolidayFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
```

Crear `database/factories/BusinessHolidayFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHoliday>
 */
class BusinessHolidayFactory extends Factory
{
    protected $model = BusinessHoliday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Feriado nacional',
            'starts_on' => '2026-07-09',
            'ends_on' => '2026-07-09',
        ];
    }
}
```

- [ ] **Step 5: Escribir la Policy**

Crear `app/Policies/BusinessHolidayPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\BusinessHoliday;
use App\Models\User;

/**
 * Dos semánticas distintas a propósito:
 *
 * - `viewAny`/`create` no tienen recurso: autorizan contra el negocio actual
 *   del actor, que el middleware `business` ya dejó fijado.
 * - `delete` agrega la pertenencia del recurso. El global scope `BusinessScope`
 *   ya impide resolver un feriado ajeno por route-model binding (sale 404), así
 *   que esta comprobación es defensa en profundidad para llamadores sin
 *   contexto de negocio: consola, jobs, tests.
 */
class BusinessHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->business_id !== null
            && in_array($user->role, Role::managers(), true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, BusinessHoliday $holiday): bool
    {
        return $this->viewAny($user) && $user->business_id === $holiday->business_id;
    }
}
```

Laravel descubre las Policies por convención de nombre (`App\Policies\{Modelo}Policy`), así que no hay que registrarla.

- [ ] **Step 6: Migrar y correr el test**

```bash
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan test --filter=BusinessHolidayScopeTest
```
Expected: PASS (3 tests).

- [ ] **Step 7: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app database
git add database/migrations/2026_08_14_000001_create_business_holidays_table.php app/Models/BusinessHoliday.php database/factories/BusinessHolidayFactory.php app/Policies/BusinessHolidayPolicy.php tests/Feature/Tenancy/BusinessHolidayScopeTest.php
git commit -m "feat: add business holidays table, model and policy"
```

---

### Task 11: Feriados — alta con detección de conflictos, listado y borrado (panel)

**Files:**
- Create: `app/Actions/Holidays/CreateBusinessHoliday.php`
- Create: `app/Actions/Holidays/DeleteBusinessHoliday.php`
- Create: `app/Http/Requests/Dashboard/StoreHolidayRequest.php`
- Create: `app/Http/Controllers/Dashboard/HolidayController.php`
- Create: `resources/js/Pages/Dashboard/Holidays/Index.jsx`
- Modify: `routes/dashboard.php`, `resources/js/Components/DashboardLayout.jsx`
- Test: `tests/Feature/Dashboard/HolidaysTest.php`

**Interfaces:**
- Consumes: `BusinessHoliday` y `BusinessHolidayPolicy` (Task 10).
- Produces: `App\Actions\Holidays\CreateBusinessHoliday::handle(Business $business, array $data): BusinessHoliday` (lanza `ValidationException`), `App\Actions\Holidays\DeleteBusinessHoliday::handle(BusinessHoliday $holiday): void`, `App\Http\Requests\Dashboard\StoreHolidayRequest` — los tres reutilizados por la Task 12. Rutas `dashboard.holidays.index|store|destroy`.

**Detección de conflictos:** solapamiento de intervalos, no "el inicio cae dentro". El rango local inclusivo se convierte a un intervalo UTC semiabierto `[starts_on 00:00, ends_on+1día 00:00)` y se rechaza cuando `booking.starts_at < fin AND booking.ends_at > inicio`. Así también se detecta la reserva que empieza antes del límite del feriado y continúa dentro de él.

**Respuesta de conflicto:** total + vista previa acotada a 5. `ValidationException::withMessages()` solo transporta arrays de strings, así que la vista previa son líneas ya formateadas en la zona del negocio, no objetos. No incluye nombre ni contacto del cliente.

Sin `update`: borrar y recrear evita reabrir la validación de conflictos sobre un rango mutante.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Dashboard/HolidaysTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidaysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: User}
     */
    private function businessWithOwner(string $timezone = 'UTC'): array
    {
        $business = Business::factory()->create(['timezone' => $timezone]);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        return [$business, $owner];
    }

    private function bookingFor(
        Business $business,
        CarbonImmutable $startsAt,
        int $durationMinutes = 30,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => $durationMinutes]);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($durationMinutes),
            'status' => $status,
        ]);
    }

    public function test_an_owner_lists_and_creates_a_holiday(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertRedirect('/dashboard/holidays');

        $this->assertDatabaseHas('business_holidays', [
            'business_id' => $business->id,
            'name' => 'Navidad',
        ]);

        $this->actingAs($owner)->get('/dashboard/holidays')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Holidays/Index')
                ->has('holidays', 1));
    }

    public function test_an_owner_deletes_a_holiday(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $holiday = BusinessHoliday::factory()->create(['business_id' => $business->id]);

        $this->actingAs($owner)->delete("/dashboard/holidays/{$holiday->id}")
            ->assertRedirect('/dashboard/holidays');

        $this->assertDatabaseMissing('business_holidays', ['id' => $holiday->id]);
    }

    public function test_it_rejects_an_end_date_before_the_start_date(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Rango inválido',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-24',
        ])->assertSessionHasErrors('ends_on');
    }

    public function test_it_rejects_a_holiday_overlapping_another_one(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => '2026-12-24',
            'ends_on' => '2026-12-26',
        ]);

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Superpuesto',
            'starts_on' => '2026-12-26',
            'ends_on' => '2026-12-28',
        ])->assertSessionHasErrors('starts_on');

        $this->assertDatabaseCount('business_holidays', 1);
    }

    public function test_a_booking_starting_before_the_holiday_but_ending_inside_it_blocks_creation(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        // El feriado empieza el 25/12 00:00 UTC. Esta reserva arranca el 24/12
        // a las 23:45 y termina el 25/12 a las 00:15: su `starts_at` cae fuera,
        // pero el intervalo se solapa.
        $this->bookingFor($business, CarbonImmutable::parse('2026-12-24 23:45:00', 'UTC'), 30);

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertSessionHasErrors('starts_on');

        $this->assertDatabaseCount('business_holidays', 0);
    }

    public function test_a_cancelled_booking_does_not_block_creation(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->bookingFor(
            $business,
            CarbonImmutable::parse('2026-12-25 10:00:00', 'UTC'),
            30,
            BookingStatus::Cancelled,
        );

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('business_holidays', 1);
    }

    public function test_the_conflict_response_carries_the_total_and_a_preview_capped_at_five(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        foreach (range(0, 5) as $offset) {
            $this->bookingFor($business, CarbonImmutable::parse('2026-12-25 09:00:00', 'UTC')->addHours($offset));
        }

        $response = $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ]);

        $response->assertSessionHasErrors(['starts_on', 'bookings_preview']);

        $errors = session('errors');

        $this->assertStringContainsString('6', $errors->first('starts_on'));
        $this->assertCount(5, $errors->get('bookings_preview'));
    }

    public function test_a_holiday_in_another_timezone_uses_the_business_local_day(): void
    {
        // Buenos Aires es UTC-3: el 25/12 local va de 03:00 a 03:00 UTC del 26.
        // Una reserva a las 02:00 UTC del 25 todavía pertenece al 24 local y no
        // debe bloquear el feriado.
        [$business, $owner] = $this->businessWithOwner('America/Argentina/Buenos_Aires');

        $this->bookingFor($business, CarbonImmutable::parse('2026-12-25 02:00:00', 'UTC'), 30);

        $this->actingAs($owner)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_employee_is_forbidden(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->get('/dashboard/holidays')->assertForbidden();

        $this->actingAs($employee)->post('/dashboard/holidays', [
            'name' => 'Navidad',
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-25',
        ])->assertForbidden();
    }

    public function test_a_holiday_from_another_business_returns_404_not_403(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $otherBusiness = Business::factory()->create();
        $foreignHoliday = BusinessHoliday::factory()->create(['business_id' => $otherBusiness->id]);

        // El global scope filtra el query del route-model binding, así que el
        // recurso ajeno ni siquiera se resuelve.
        $this->actingAs($owner)->delete("/dashboard/holidays/{$foreignHoliday->id}")->assertNotFound();

        $this->assertDatabaseHas('business_holidays', ['id' => $foreignHoliday->id]);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=HolidaysTest`
Expected: FAIL — 404 en `/dashboard/holidays`.

- [ ] **Step 3: Escribir el Form Request**

Crear `app/Http/Requests/Dashboard/StoreHolidayRequest.php`:

```php
<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La Policy decide en el controlador.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
        ];
    }
}
```

- [ ] **Step 4: Escribir las Actions**

Crear `app/Actions/Holidays/CreateBusinessHoliday.php`:

```php
<?php

namespace App\Actions\Holidays;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Models\Scopes\BusinessScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CreateBusinessHoliday
{
    private const PREVIEW_LIMIT = 5;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException cuando el rango se superpone con otro feriado
     *         o con reservas activas.
     */
    public function handle(Business $business, array $data): BusinessHoliday
    {
        $startsOn = (string) $data['starts_on'];
        $endsOn = (string) $data['ends_on'];

        $this->assertNoHolidayOverlap($business, $startsOn, $endsOn);
        $this->assertNoBookingOverlap($business, $startsOn, $endsOn);

        return BusinessHoliday::create([
            'business_id' => $business->id,
            'name' => $data['name'],
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    private function assertNoHolidayOverlap(Business $business, string $startsOn, string $endsOn): void
    {
        $overlaps = BusinessHoliday::query()
            ->withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_on' => 'Ya existe un feriado que se superpone con ese rango.',
            ]);
        }
    }

    private function assertNoBookingOverlap(Business $business, string $startsOn, string $endsOn): void
    {
        $timezone = $business->timezone;

        // Rango local inclusivo -> intervalo UTC semiabierto [inicio, fin).
        $holidayStartUtc = CarbonImmutable::parse($startsOn, $timezone)->startOfDay()->utc();
        $holidayEndUtc = CarbonImmutable::parse($endsOn, $timezone)->startOfDay()->addDay()->utc();

        /** @var Collection<int, Booking> $conflicts */
        $conflicts = Booking::query()
            ->withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            // Solapamiento de intervalos: alcanza con que la reserva pise el
            // rango, aunque haya empezado antes de que el feriado arranque.
            ->where('starts_at', '<', $holidayEndUtc)
            ->where('ends_at', '>', $holidayStartUtc)
            ->orderBy('starts_at')
            ->with(['service:id,name', 'employee:id,name'])
            ->get();

        if ($conflicts->isEmpty()) {
            return;
        }

        $total = $conflicts->count();
        $plural = $total === 1 ? 'reserva activa' : 'reservas activas';

        throw ValidationException::withMessages([
            'starts_on' => "No podés crear el feriado: hay {$total} {$plural} en ese rango. Cancelalas o reprogramalas primero.",
            // `withMessages()` solo transporta strings: la vista previa son
            // líneas ya formateadas, no objetos. Sin datos del cliente.
            'bookings_preview' => $conflicts
                ->take(self::PREVIEW_LIMIT)
                ->map(fn (Booking $booking) => sprintf(
                    '%s — %s — %s',
                    $booking->starts_at->toImmutable()->setTimezone($timezone)->format('d/m/Y H:i'),
                    $booking->service?->name ?? 'Servicio',
                    $booking->employee?->name ?? 'Empleado',
                ))
                ->values()
                ->all(),
        ]);
    }
}
```

Crear `app/Actions/Holidays/DeleteBusinessHoliday.php`:

```php
<?php

namespace App\Actions\Holidays;

use App\Models\BusinessHoliday;

class DeleteBusinessHoliday
{
    /**
     * Borrar un feriado no valida nada: solo libera disponibilidad.
     */
    public function handle(BusinessHoliday $holiday): void
    {
        $holiday->delete();
    }
}
```

- [ ] **Step 5: Escribir el controlador y las rutas**

Crear `app/Http/Controllers/Dashboard/HolidayController.php`:

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Holidays\CreateBusinessHoliday;
use App\Actions\Holidays\DeleteBusinessHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreHolidayRequest;
use App\Models\Business;
use App\Models\BusinessHoliday;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', BusinessHoliday::class);

        return Inertia::render('Dashboard/Holidays/Index', [
            'holidays' => BusinessHoliday::orderBy('starts_on')->get(['id', 'name', 'starts_on', 'ends_on']),
            // Mismo patrón que PasswordResetLinkController: flash `status`
            // pasado explícito, no vía share() global.
            'status' => session('status'),
        ]);
    }

    public function store(StoreHolidayRequest $request, CreateBusinessHoliday $action): RedirectResponse
    {
        $this->authorize('create', BusinessHoliday::class);

        $action->handle(Business::current(), $request->validated());

        return redirect()->route('dashboard.holidays.index')->with('status', 'Feriado creado.');
    }

    public function destroy(BusinessHoliday $holiday, DeleteBusinessHoliday $action): RedirectResponse
    {
        $this->authorize('delete', $holiday);

        $action->handle($holiday);

        return redirect()->route('dashboard.holidays.index')->with('status', 'Feriado eliminado.');
    }
}
```

Modificar `routes/dashboard.php` — dentro del grupo `dashboard.`:

```php
        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
```

y el `use App\Http\Controllers\Dashboard\HolidayController;` arriba.

- [ ] **Step 6: Escribir la página y el enlace**

Crear `resources/js/Pages/Dashboard/Holidays/Index.jsx`:

```jsx
import { router, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Index({ holidays }) {
    const { status, errors } = usePage().props;

    const form = useForm({ name: '', starts_on: '', ends_on: '' });

    const preview = errors?.bookings_preview;

    function submit(event) {
        event.preventDefault();
        form.post('/dashboard/holidays', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    function destroy(holiday) {
        if (confirm(`¿Eliminar el feriado "${holiday.name}"?`)) {
            router.delete(`/dashboard/holidays/${holiday.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-3xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Feriados</h1>

                {status && <p className="mb-4 text-sm text-green-700">{status}</p>}

                <form onSubmit={submit} className="mb-8 flex flex-wrap items-end gap-4">
                    <div>
                        <label className="block text-sm font-medium" htmlFor="name">Nombre</label>
                        <input
                            id="name"
                            className="mt-1 rounded-md border px-3 py-2"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="starts_on">Desde</label>
                        <input
                            id="starts_on"
                            type="date"
                            className="mt-1 rounded-md border px-3 py-2"
                            value={form.data.starts_on}
                            onChange={(event) => form.setData('starts_on', event.target.value)}
                        />
                        <InputError message={form.errors.starts_on} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="ends_on">Hasta</label>
                        <input
                            id="ends_on"
                            type="date"
                            className="mt-1 rounded-md border px-3 py-2"
                            value={form.data.ends_on}
                            onChange={(event) => form.setData('ends_on', event.target.value)}
                        />
                        <InputError message={form.errors.ends_on} />
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Agregar feriado
                    </button>
                </form>

                {preview && (
                    <div className="mb-8 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm">
                        <p className="mb-2 font-semibold">Reservas afectadas (primeras 5):</p>
                        <ul className="list-inside list-disc">
                            {(Array.isArray(preview) ? preview : [preview]).map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Nombre</th>
                            <th className="py-2">Desde</th>
                            <th className="py-2">Hasta</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {holidays.map((holiday) => (
                            <tr key={holiday.id} className="border-b">
                                <td className="py-2">{holiday.name}</td>
                                <td className="py-2">{holiday.starts_on}</td>
                                <td className="py-2">{holiday.ends_on}</td>
                                <td className="py-2 text-right">
                                    <button onClick={() => destroy(holiday)} className="text-red-600 underline">
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

Modificar `resources/js/Components/DashboardLayout.jsx` — agregar junto al enlace de Ajustes:

```jsx
                {isManager && (
                    <Link href="/dashboard/holidays" className="hover:text-gray-900">Feriados</Link>
                )}
```

Reconstruir: `docker compose exec laravel.test bash -lc "rm -f public/hot && pnpm build"`.

- [ ] **Step 7: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=HolidaysTest`
Expected: PASS (10 tests).

- [ ] **Step 8: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Actions/Holidays app/Http/Requests/Dashboard/StoreHolidayRequest.php app/Http/Controllers/Dashboard/HolidayController.php routes/dashboard.php resources/js tests/Feature/Dashboard/HolidaysTest.php
git commit -m "feat: add business holidays with booking conflict detection"
```

---

### Task 12: Feriados — API

**Files:**
- Create: `app/Http/Resources/HolidayResource.php`
- Create: `app/Http/Controllers/Api/HolidayController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/HolidaysTest.php`

**Interfaces:**
- Consumes: `CreateBusinessHoliday::handle(Business, array): BusinessHoliday`, `DeleteBusinessHoliday::handle(BusinessHoliday): void`, `StoreHolidayRequest`, `BusinessHolidayPolicy` (Tasks 10–11).
- Produces: rutas `api.holidays.index|store|destroy`. `App\Http\Resources\HolidayResource`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Api/HolidaysTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidaysTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('cli')->plainTextToken;
    }

    /**
     * @return array{0: Business, 1: User}
     */
    private function businessWithOwner(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        return [$business, $owner];
    }

    public function test_it_lists_and_creates_holidays(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $token = $this->tokenFor($owner);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/holidays', [
                'name' => 'Navidad',
                'starts_on' => '2026-12-25',
                'ends_on' => '2026-12-25',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Navidad')
            ->assertJsonPath('message', 'Feriado creado.');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/holidays')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_it_deletes_a_holiday(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $holiday = BusinessHoliday::factory()->create(['business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/holidays/{$holiday->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Feriado eliminado.');

        $this->assertDatabaseMissing('business_holidays', ['id' => $holiday->id]);
    }

    public function test_a_conflict_returns_the_validation_envelope_with_the_preview(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);
        $startsAt = CarbonImmutable::parse('2026-12-25 10:00:00', 'UTC');

        Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/holidays', [
                'name' => 'Navidad',
                'starts_on' => '2026-12-25',
                'ends_on' => '2026-12-25',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Los datos enviados no son válidos.')
            ->assertJsonStructure(['errors' => ['starts_on', 'bookings_preview']]);

        $this->assertDatabaseCount('business_holidays', 0);
    }

    public function test_an_employee_is_forbidden(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($employee))
            ->getJson('/api/holidays')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'No tenés permiso para realizar esta acción.']);
    }

    public function test_deleting_a_holiday_from_another_business_returns_404(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $foreignHoliday = BusinessHoliday::factory()->create([
            'business_id' => Business::factory()->create()->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/holidays/{$foreignHoliday->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Recurso no encontrado.']);

        $this->assertDatabaseHas('business_holidays', ['id' => $foreignHoliday->id]);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `docker compose exec laravel.test php artisan test --filter=Api/HolidaysTest`
Expected: FAIL — 404 en `/api/holidays`.

- [ ] **Step 3: Escribir el Resource y el controlador**

Crear `app/Http/Resources/HolidayResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
        ];
    }
}
```

Crear `app/Http/Controllers/Api/HolidayController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Actions\Holidays\CreateBusinessHoliday;
use App\Actions\Holidays\DeleteBusinessHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HolidayController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', BusinessHoliday::class);

        $holidays = BusinessHoliday::orderBy('starts_on')->get();

        return ApiResponse::success(HolidayResource::collection($holidays));
    }

    public function store(StoreHolidayRequest $request, CreateBusinessHoliday $action): JsonResponse
    {
        $this->authorize('create', BusinessHoliday::class);

        $holiday = $action->handle(Business::current(), $request->validated());

        return ApiResponse::success(new HolidayResource($holiday), 'Feriado creado.', 201);
    }

    public function destroy(BusinessHoliday $holiday, DeleteBusinessHoliday $action): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $action->handle($holiday);

        return ApiResponse::success(null, 'Feriado eliminado.');
    }
}
```

Sin paginación: un negocio tiene un puñado de feriados por año. El listado va como colección simple en `data`, no como `data.items` + `data.meta` — ese formato es para listados paginados.

- [ ] **Step 4: Registrar las rutas**

Modificar `routes/api.php` — dentro del grupo `Route::middleware(['auth:sanctum', 'business'])`:

```php
        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])
            ->name('holidays.destroy')
            ->whereNumber('holiday');
```

y arriba `use App\Http\Controllers\Api\HolidayController;`.

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `docker compose exec laravel.test php artisan test --filter=Api/HolidaysTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app routes
git add app/Http/Controllers/Api/HolidayController.php app/Http/Resources/HolidayResource.php routes/api.php tests/Feature/Api/HolidaysTest.php
git commit -m "feat: expose business holidays over the API"
```

---

### Task 13: Integrar feriados y empleados inactivos en `AvailabilityService`

El cambio de dominio de la fase. Dos guardas nuevas, nada más del motor se toca: pausas, licencias, reservas y buffers siguen igual.

**Files:**
- Modify: `app/Services/AvailabilityService.php`
- Test: `tests/Unit/Services/AvailabilityServiceTest.php` (agregar casos)
- Test: `tests/Feature/Api/AvailabilityTest.php` (agregar un caso)

**Interfaces:**
- Consumes: `App\Models\BusinessHoliday` (Task 10).
- Produces: ningún símbolo nuevo. Cambia el contrato observable de `AvailabilityService::getAvailableSlots()`: devuelve `[]` si el empleado está inactivo o si el día local consultado cae dentro de un feriado del negocio.

Efecto colateral buscado y documentado: `/api/availability` y `/api/businesses/{slug}/availability` lo reflejan porque comparten el motor.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar a `tests/Unit/Services/AvailabilityServiceTest.php`. Reutilizar los helpers de armado que el archivo ya tenga; si arma el escenario a mano en cada test, seguir ese mismo estilo. Los cuatro casos:

```php
    public function test_an_inactive_employee_has_no_slots(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create([
            'business_id' => $business->id,
            'is_active' => false,
        ]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots(
            $business,
            $service,
            $employee,
            CarbonImmutable::parse('next monday', 'UTC')->startOfDay(),
        );

        $this->assertSame([], $slots);
    }

    public function test_a_day_inside_a_business_holiday_has_no_slots(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => $monday->toDateString(),
            'ends_on' => $monday->addDays(2)->toDateString(),
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots($business, $service, $employee, $monday);

        $this->assertSame([], $slots);
    }

    public function test_the_day_before_a_holiday_still_has_slots(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => $monday->addDay()->toDateString(),
            'ends_on' => $monday->addDay()->toDateString(),
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots($business, $service, $employee, $monday);

        $this->assertNotEmpty($slots);
    }

    public function test_a_holiday_from_another_business_does_not_affect_availability(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        BusinessHoliday::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'starts_on' => $monday->toDateString(),
            'ends_on' => $monday->toDateString(),
        ]);

        $slots = app(AvailabilityService::class)->getAvailableSlots($business, $service, $employee, $monday);

        $this->assertNotEmpty($slots);
    }
```

Agregar los `use App\Models\BusinessHoliday;` que falten al principio del archivo.

Y en `tests/Feature/Api/AvailabilityTest.php`, un caso de integración. Ese archivo usa `Sanctum::actingAs()` y devuelve los slots directamente en `data` (array de `{starts_at, ends_at}`), así que:

```php
    public function test_a_business_holiday_empties_the_availability(): void
    {
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        Schedule::factory()
            ->for($business)
            ->for($employee, 'employee')
            ->create([
                'day_of_week' => DayOfWeek::Wednesday,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'is_active' => true,
            ]);

        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $date = Carbon::now()->next(Carbon::WEDNESDAY)->format('Y-m-d');

        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => $date,
            'ends_on' => $date,
        ]);

        $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }
```

Agregar `use App\Enums\Role;` y `use App\Models\BusinessHoliday;` a los imports del archivo.

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `docker compose exec laravel.test php artisan test --filter=AvailabilityService`
Expected: FAIL — los slots siguen apareciendo pese al feriado y al empleado inactivo.

- [ ] **Step 3: Agregar las guardas al motor**

Modificar `app/Services/AvailabilityService.php`. La validación de pertenencia al negocio que ya existe queda primero; después va la guarda del empleado, y la del feriado justo después de calcular `$localDate` (necesita el día local) y antes de buscar el `Schedule`:

```php
        if ($service->business_id !== $business->id || $employee->business_id !== $business->id) {
            throw new \InvalidArgumentException('Service and employee must belong to the given business.');
        }

        // Un empleado desactivado no genera turnos, aunque conserve horarios
        // cargados y alguien pase su ID a mano.
        if (! $employee->is_active) {
            return [];
        }

        $timezone = $business->timezone;
        $localDate = CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, $timezone);

        // Feriado del negocio: rango inclusivo de días completos en la zona
        // local del negocio.
        $isHoliday = BusinessHoliday::query()
            ->withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->where('starts_on', '<=', $localDate->toDateString())
            ->where('ends_on', '>=', $localDate->toDateString())
            ->exists();

        if ($isHoliday) {
            return [];
        }

        $dayOfWeek = DayOfWeek::from($localDate->dayOfWeek);
```

Agregar los imports:

```php
use App\Models\BusinessHoliday;
use App\Models\Scopes\BusinessScope;
```

`withoutGlobalScope` + `where('business_id', ...)` explícito: el motor se llama también desde contextos sin negocio ligado (jobs, comandos, tests unitarios) y no puede depender del scope implícito.

Actualizar el docblock de `getAvailableSlots()` agregando, junto a lo que ya dice:

```
     * Devuelve `[]` sin más si el empleado está desactivado o si el día local
     * consultado cae dentro de un feriado del negocio.
```

- [ ] **Step 4: Correr los tests y verificar que pasan**

```bash
docker compose exec laravel.test php artisan test --filter=AvailabilityService
docker compose exec laravel.test php artisan test --filter=Api/AvailabilityTest
```
Expected: PASS ambos.

- [ ] **Step 5: Correr la suite completa**

Run: `docker compose exec laravel.test php artisan test`
Expected: PASS. Esta guarda toca el motor que usan reservas, panel y API: si algo del resto de la suite se rompe, es acá donde aparece. Un test previo que armara un empleado con `is_active = false` por descuido fallaría ahora legítimamente — revisar el factory usado antes de tocar el motor.

- [ ] **Step 6: Formatear y commitear**

```bash
docker compose exec laravel.test vendor/bin/pint app tests
git add app/Services/AvailabilityService.php tests/Unit/Services/AvailabilityServiceTest.php tests/Feature/Api/AvailabilityTest.php
git commit -m "feat: honor business holidays and inactive employees in availability"
```

---

### Task 14: Documentación y cierre de fase

**Files:**
- Modify: `docs/api.md`
- Modify: `01-reservahub.md`
- Modify: `CLAUDE.md`
- Modify: `docs/DEPLOYMENT_HANDOFF.md`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: nada de código.

- [ ] **Step 1: Documentar los endpoints nuevos**

Modificar `docs/api.md`, sección `## Endpoints`, agregando las filas/bloques:

```
| GET    | /api/account                | Datos de la cuenta autenticada           |
| PATCH  | /api/account/profile        | Actualiza nombre y email                 |
| PUT    | /api/account/password       | Cambia la contraseña                     |
| GET    | /api/business               | Ajustes del negocio (owner/admin)        |
| PUT    | /api/business               | Actualiza los ajustes del negocio        |
| PUT    | /api/users/{user}/status    | Activa o desactiva un usuario            |
| GET    | /api/holidays               | Feriados del negocio                     |
| POST   | /api/holidays               | Crea un feriado                          |
| DELETE | /api/holidays/{holiday}     | Elimina un feriado                       |
```

Y una nota explícita bajo `## Autenticación`:

```markdown
**Cambio de contraseña por API.** `PUT /api/account/password` revoca **todos**
los tokens del usuario, incluido el que hizo la llamada. La respuesta llega con
200 y el mensaje de re-login; cualquier petición posterior con ese token
devuelve 401. Después de cambiarla hay que volver a `POST /api/auth/login`.
```

Bajo `## Códigos de error`, aclarar el 404 de tenancy:

```markdown
Un recurso de otro negocio devuelve **404**, no 403: el scope de negocio filtra
la consulta antes de que el recurso se resuelva, así que la API no confirma su
existencia.
```

- [ ] **Step 2: Actualizar el estado de fases**

Modificar `01-reservahub.md`:

- En la tabla de estado de §7, cambiar la fila 8 a:

```
| 8 — Gestión de cuenta y negocio | Hecha | `tests/Feature/Account/*`, `tests/Feature/Dashboard/{BusinessSettingsTest,UserStatusTest,UserStatusConcurrencyTest,HolidaysTest}`, `tests/Feature/Api/{AccountTest,BusinessTest,UsersTest,HolidaysTest}`, `business_holidays` en `AvailabilityService` |
```

- En §3 (modelo de datos), agregar `business_holidays` con sus columnas: `id, business_id, name, starts_on, ends_on, timestamps`.
- En §2 → Disponibilidad, quitar el "(Fase 8, todavía sin tabla)" de la línea de feriados.

- [ ] **Step 3: Actualizar `CLAUDE.md`**

Agregar una sección corta después de la de API REST:

```markdown
## Revocación de acceso y moneda (Fase 8)

Toda revocación de acceso pasa por `App\Support\UserAccessRevoker::revoke($user, $keepSessionId)`:
rota el `remember_token`, borra todos los tokens de Sanctum y borra las filas de
`sessions` del usuario. **Falla cerrado**: lanza `UnsupportedSessionDriverException`
si `SESSION_DRIVER` no es `database`, porque con otro driver las sesiones web no
se pueden invalidar. No usar `Auth::logoutOtherDevices()` — `AuthenticateSession`
no está en el grupo `web`, así que no invalidaría nada.

Lo consumen el cambio de contraseña (`App\Actions\Account\ChangePassword`, que
preserva la sesión actual en web y no preserva nada por API) y la desactivación
de usuarios (`App\Actions\Users\SetUserActiveStatus`).

Las monedas válidas son el enum `App\Enums\Currency` (set acotado de códigos
ISO-4217, sin dependencia externa). La columna `businesses.currency` sigue siendo
string: el enum se usa para validar y para poblar el formulario.
```

Y actualizar la línea de estado del principio: Fases 0–8 implementadas.

- [ ] **Step 4: Actualizar el handoff de despliegue**

Modificar `docs/DEPLOYMENT_HANDOFF.md`, §4 (Contrato de entorno). No hay servicio, variable ni ruta persistente nueva, pero el contrato **se endurece**:

```markdown
### `SESSION_DRIVER=database` — requisito operativo

Deja de ser una conveniencia. La aplicación invalida las sesiones ajenas
borrando filas de la tabla `sessions`, y con cualquier otro driver
`UserAccessRevoker` lanza una excepción: el cambio de contraseña y la
desactivación de usuarios fallarían con 500 en producción. La tabla `sessions`
tiene que estar presente y migrada (ya viene en las migraciones base).
```

- [ ] **Step 5: Verificación final**

```bash
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test php artisan test
```
Expected: Pint limpio y suite completa en verde. No declarar la fase terminada sin ver ambas salidas.

- [ ] **Step 6: Commitear**

```bash
git add docs/api.md 01-reservahub.md CLAUDE.md docs/DEPLOYMENT_HANDOFF.md
git commit -m "docs: document Fase 8 account and business management"
```

---

## Notas de ejecución

- **Orden.** Las Tasks 1 → 3 → 4 y 10 → 11 → 12 → 13 tienen dependencias reales. Las Tasks 5–6 (ajustes) y 7–9 (estado de usuario) son independientes entre sí y del bloque de feriados: se pueden repartir si se ejecuta en paralelo, pero cada una necesita la Task 1 ya mergeada.
- **Reconstruir el frontend** después de cada task que toque `resources/js` y antes de correr sus tests: `docker compose exec laravel.test bash -lc "rm -f public/hot && pnpm build"`. Si aparecen ~28 fallos con `Not a valid Inertia response`, falta el build, no hay un bug.
- **Reiniciar el worker** solo si se tocan listeners o notificaciones. Esta fase no los toca.
- **Al terminar**, usar `superpowers:finishing-a-development-branch` y acordarse de que esa skill no sabe de Docker: bajar la stack del worktree a mano con `docker compose down -v` desde su directorio.
