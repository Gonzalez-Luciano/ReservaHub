# Fase 0 Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scaffold a working Laravel 12 + Inertia + React + Tailwind app, running in dev via Laravel Sail against Postgres, with no auth scaffolding (that's Fase 1).

**Architecture:** Plain `laravel/laravel` skeleton scaffolded into the existing repo root (which already holds `01-reservahub.md`, `CLAUDE.md`, `docs/`), then Tailwind, Inertia, and React added manually (no starter kit, so no bundled auth pages), then Laravel Sail added for a Postgres/Redis/Mailpit dev environment.

**Tech Stack:** Laravel 12, PHP 8.3, Inertia.js (Laravel adapter + React adapter), React 18, Tailwind CSS v4, Vite, Laravel Sail, PostgreSQL, Redis.

## Global Constraints

- No auth scaffolding of any kind in this plan — no login/register pages, no Breeze/Jetstream/starter-kit. That is Fase 1.
- Git repo already initialized by the user (branch `main`, no commits yet). Each task ends with a local commit (needed for the subagent-driven-development review flow) — but no `git push`, no remote, no branch changes beyond `main`. The user handles all remote/push operations themselves.
- DB is PostgreSQL, dev environment is Laravel Sail (`pgsql` template) — not a hand-written Dockerfile (that's Fase 10, for production).
- Existing files at repo root (`01-reservahub.md`, `CLAUDE.md`, `docs/`) must survive scaffolding untouched.
- Tailwind v4 (`@tailwindcss/vite` plugin, `@import "tailwindcss";` in CSS) — not the v3 config-file approach.

---

### Task 1: Scaffold Laravel app into the existing repo root

**Files:**
- Create: full Laravel skeleton at repo root (`composer.json`, `artisan`, `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `vite.config.js`, `package.json`, `.env.example`, etc.)
- Preserve: `01-reservahub.md`, `CLAUDE.md`, `docs/` (must not be overwritten or deleted)

**Interfaces:**
- Produces: a runnable Laravel 12 app at repo root, `php artisan` usable from repo root.

- [ ] **Step 1: Scaffold into a temp subfolder (repo root is not empty, so `composer create-project` can't target it directly)**

Run from the repo root (`C:\Users\lucho\Desktop\Proyectos-Laravel\reservahub`):

```bash
composer create-project laravel/laravel _scaffold_tmp
```

- [ ] **Step 2: Move the scaffolded contents (including dotfiles) up into the repo root**

```bash
shopt -s dotglob
mv _scaffold_tmp/* .
rmdir _scaffold_tmp
```

- [ ] **Step 3: Verify existing project files were not clobbered**

```bash
ls 01-reservahub.md CLAUDE.md docs/superpowers/specs docs/superpowers/plans
```

Expected: all four paths still exist and are unchanged (the two spec/plan `.md` files from this session should still be listed under `docs/superpowers/...`).

- [ ] **Step 4: Verify the Laravel app itself works**

```bash
php artisan --version
```

Expected: prints `Laravel Framework 12.x.x` with no error.

- [ ] **Step 5: Generate app key and verify config loads**

```bash
php artisan key:generate
php artisan about
```

Expected: `php artisan about` prints an environment/config summary with no exceptions.

---

### Task 2: Install Tailwind CSS v4

**Files:**
- Modify: `resources/css/app.css`
- Modify: `vite.config.js`
- Modify: `package.json` (via npm install)

**Interfaces:**
- Consumes: Vite config produced in Task 1's scaffold (default `laravel-vite-plugin` entry).
- Produces: Tailwind utility classes usable in any Blade/React file after `@import "tailwindcss";` is present in `resources/css/app.css`.

- [ ] **Step 1: Install Tailwind's Vite plugin**

```bash
npm install tailwindcss @tailwindcss/vite
```

- [ ] **Step 2: Wire the plugin into Vite config**

Edit `vite.config.js` to match:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
```

(Task 3 will change the `resources/js/app.js` entry to `app.jsx` — leave as `app.js` for now, this task only proves Tailwind compiles.)

- [ ] **Step 3: Replace the default CSS with the Tailwind import**

Replace the full contents of `resources/css/app.css` with:

```css
@import "tailwindcss";
```

- [ ] **Step 4: Verify Tailwind builds**

```bash
npm run build
```

Expected: build succeeds, `public/build/assets/*.css` contains compiled Tailwind output (check with `grep -c tailwind public/build/manifest.json` or just confirm the build exits 0 with a `.css` asset listed).

---

### Task 3: Install Inertia + React, render a placeholder Home page

**Files:**
- Create: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/views/app.blade.php`
- Create: `resources/js/app.jsx`
- Create: `resources/js/Pages/Home.jsx`
- Modify: `bootstrap/app.php` (register Inertia middleware)
- Modify: `routes/web.php` (render `Home` page at `/`)
- Modify: `vite.config.js` (add React plugin, switch entry to `app.jsx`)
- Delete: `resources/js/app.js` (replaced by `app.jsx`)
- Delete: default `resources/views/welcome.blade.php` (replaced by Inertia root view + Home page)

**Interfaces:**
- Consumes: Tailwind-enabled `vite.config.js` and `resources/css/app.css` from Task 2.
- Produces: `Inertia::render('Home')` renders `resources/js/Pages/Home.jsx` at route `/`. Later fases add more entries under `resources/js/Pages/`.

- [ ] **Step 1: Install backend Inertia adapter**

```bash
composer require inertiajs/inertia-laravel
```

- [ ] **Step 2: Create the Inertia middleware**

```bash
php artisan inertia:middleware
```

This creates `app/Http/Middleware/HandleInertiaRequests.php`. Confirm it exists and extends `Inertia\Middleware`.

- [ ] **Step 3: Register the middleware in the web group**

Edit `bootstrap/app.php` — inside `->withMiddleware(function (Middleware $middleware) { ... })`, add:

```php
$middleware->web(append: [
    \App\Http\Middleware\HandleInertiaRequests::class,
]);
```

- [ ] **Step 4: Create the Inertia root Blade view**

Create `resources/views/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>ReservaHub</title>
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
```

Delete `resources/views/welcome.blade.php` — it's no longer used.

- [ ] **Step 5: Install frontend Inertia + React packages**

```bash
npm install @inertiajs/react react react-dom
npm install -D @vitejs/plugin-react
```

- [ ] **Step 6: Rename the JS entrypoint and write the Inertia bootstrap**

Delete `resources/js/app.js`. Create `resources/js/app.jsx`:

```jsx
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
```

- [ ] **Step 7: Update `vite.config.js` to add the React plugin and the new entrypoint**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
});
```

- [ ] **Step 8: Create the placeholder Home page**

Create `resources/js/Pages/Home.jsx`:

```jsx
export default function Home() {
    return (
        <div className="min-h-screen flex items-center justify-center">
            <h1 className="text-3xl font-bold">ReservaHub</h1>
        </div>
    );
}
```

- [ ] **Step 9: Wire the root route to render it**

Replace the default welcome route in `routes/web.php` with:

```php
<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
});
```

- [ ] **Step 10: Verify the build and the page**

```bash
npm run build
php artisan serve
```

In a browser, open the printed `http://127.0.0.1:8000/` — expected: a page showing "ReservaHub" styled with Tailwind (large bold text, centered). Check browser devtools console: no JS errors. Stop `php artisan serve` after confirming (Ctrl+C).

---

### Task 4: Add Laravel Sail with the `pgsql` template

**Files:**
- Create: `docker-compose.yml` (generated by `sail:install`)
- Modify: `.env` and `.env.example` (DB connection vars, generated by `sail:install`)
- Modify: `composer.json` (adds `laravel/sail` to `require-dev`, via composer)

**Interfaces:**
- Consumes: working app from Tasks 1-3.
- Produces: `./vendor/bin/sail` CLI wrapping `docker compose`, with services `laravel.test` (app), `pgsql`, `redis`, `mailpit`. Later fases run `sail artisan migrate`, `sail test`, etc. against this stack.

- [ ] **Step 1: Require Sail as a dev dependency**

```bash
composer require laravel/sail --dev
```

- [ ] **Step 2: Install the Sail Docker config with the Postgres template**

```bash
php artisan sail:install --with=pgsql,redis,mailpit
```

Expected: creates/updates `docker-compose.yml` at repo root, and updates `.env` so `DB_CONNECTION=pgsql`, `DB_HOST=pgsql`, `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` point at the `pgsql` service, plus `REDIS_HOST=redis` and mail vars pointing at Mailpit.

- [ ] **Step 3: Mirror the DB/Redis/mail vars into `.env.example`**

Open `.env` and `.env.example` side by side; copy the `DB_*`, `REDIS_*`, `MAIL_*` values that `sail:install` wrote into `.env` over into `.env.example` too (so a fresh clone has correct defaults). Do not copy `APP_KEY` or any secret value — leave those blank in `.env.example`.

- [ ] **Step 4: Confirm Docker Desktop is running**

```bash
docker info
```

Expected: prints Docker server info with no connection error. If this fails, start Docker Desktop before continuing — this step cannot be scripted further.

- [ ] **Step 5: Bring the stack up**

```bash
./vendor/bin/sail up -d
```

Expected: `laravel.test`, `pgsql`, `redis`, `mailpit` containers start and report healthy/running (`docker ps` should list all four).

- [ ] **Step 6: Run migrations against Postgres**

```bash
./vendor/bin/sail artisan migrate
```

Expected: default Laravel migrations (`users`, `cache`, `jobs`, etc.) run with no error, against the `pgsql` container.

- [ ] **Step 7: Verify the app serves through Sail**

```bash
./vendor/bin/sail artisan about
```

Expected: no exceptions, and the printed environment section shows `pgsql` as the DB driver.

Then open `http://localhost/` in a browser (Sail's `laravel.test` service publishes port 80 by default) — expected: same "ReservaHub" page as Task 3's Step 10.

- [ ] **Step 8: Tear down (leave it stoppable, not necessarily running)**

```bash
./vendor/bin/sail stop
```

This confirms the stack starts and stops cleanly; the user can bring it back up with `sail up -d` whenever they resume work.

---

### Task 5: Final acceptance check against the Fase 0 spec

**Files:** none created — verification only, cross-referencing `docs/superpowers/specs/2026-08-05-fase0-bootstrap-design.md`.

- [ ] **Step 1: Re-run every "resultado esperado" item from the spec in one pass**

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm run build
```

- [ ] **Step 2: Confirm each spec criterion**

1. `sail up -d` → 4 containers running, no error.
2. `sail artisan migrate` → runs cleanly against Postgres.
3. Visiting `/` → React "ReservaHub" page renders via Inertia.
4. `.env.example` → has correct Sail/Postgres/Redis/Mail vars (from Task 4 Step 3).
5. `composer.json` / `package.json` → list Laravel 12, `inertiajs/inertia-laravel`, `laravel/sail` (composer) and `@inertiajs/react`, `react`, `react-dom`, `@vitejs/plugin-react`, `tailwindcss`, `@tailwindcss/vite` (npm).

- [ ] **Step 3: Report status to the user**

Summarize pass/fail on each of the 5 criteria above. Do not commit anything — the user handles git themselves.
