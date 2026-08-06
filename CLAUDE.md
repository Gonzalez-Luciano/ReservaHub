# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

The Laravel app is scaffolded and this is a git repository (Fase 0 and Fase 1 are done — see `docs/superpowers/plans/`). `01-reservahub.md` is still the authoritative spec for anything not yet implemented.

Standard Laravel commands apply: `php artisan test` (or a specific test with `php artisan test --filter=TestName`), `vendor/bin/pint --test` for formatting. For the frontend, see **Package manager** below before running any JS command.

## Development environment: Docker (Laravel Sail)

The project runs under Docker via Laravel Sail — `compose.yaml` at the repo root defines `laravel.test` (app), `pgsql`, `redis`, and `mailpit`. There is no working native (non-Docker) path: `.env`'s `DB_HOST`/`REDIS_HOST`/`MAIL_HOST` point at Docker service names (`pgsql`, `redis`, `mailpit`), which only resolve inside the `laravel.test` container's network. Always develop and test against the containers, not `php artisan serve` on the host.

**`vendor/bin/sail` does not work in Git Bash on Windows** — it hard-refuses with `Unsupported operating system [MINGW64_NT-...]`. Use `docker compose` directly instead, and pass dummy `WWWUSER`/`WWWGROUP` values (Sail's wrapper normally injects these; without it you'll get harmless "variable not set" warnings but the containers still run fine):

```bash
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
docker compose exec laravel.test php artisan migrate:fresh --force
docker compose exec laravel.test php artisan test
docker compose exec laravel.test vendor/bin/pint --test
```

**Testing a feature branch or worktree in parallel with the main checkout:** `docker compose`'s default project name is derived from the current directory's basename, so running `docker compose up -d` from a git worktree at a different path (e.g. `.claude/worktrees/<branch-name>/`) automatically gets its own project name, containers, network, and volumes — it won't collide with a stack already running from the main checkout. It *will* collide on **host ports** though (both stacks default to 80, 5432, 6379, 1025, 8025, 5173), so before bringing up a second stack, add distinct forwarded ports to that worktree's `.env` (values must stay ≤ 65535):

```
APP_URL=http://localhost:8180
APP_PORT=8180
FORWARD_DB_PORT=54320
FORWARD_REDIS_PORT=63790
FORWARD_MAILPIT_PORT=10250
FORWARD_MAILPIT_DASHBOARD_PORT=8026
VITE_PORT=5273
```
Also set `DB_HOST=pgsql` in that `.env` (not `127.0.0.1` — that only works for a *native* host process talking to a container's forwarded port, and there is no working native path here per above). Mailpit's dashboard (to inspect sent verification/reset emails) is then at `http://localhost:<FORWARD_MAILPIT_DASHBOARD_PORT>`.

**Running the frontend build in a container:** if the JS dev server (`pnpm dev`) was ever run natively on the host against this same working directory, it writes `public/hot`, which makes Laravel's `@vite` directive emit script tags pointing at that (now-dead) native Vite server instead of the built assets — resulting in a blank page with a console `@vitejs/plugin-react can't detect preamble` error. Delete `public/hot` (`rm -f public/hot`) and run `pnpm build` if you hit this.

**Tearing down a worktree's stack:** `docker compose down -v` (from that worktree's directory) before or when the worktree itself is removed. The `superpowers:finishing-a-development-branch` skill's cleanup step only runs `git worktree remove` — it has no knowledge of Docker, so a Sail stack brought up for manual testing in a worktree is never torn down automatically and will keep running (and holding its forwarded ports) after the branch is merged unless done by hand.

## Package manager: pnpm, not npm

This project uses **pnpm**, not npm — there is no `package-lock.json`, only `pnpm-lock.yaml`. Always use `pnpm install` / `pnpm dev` / `pnpm build`, never the `npm` equivalents.

## What this is

ReservaHub is a SaaS booking/appointment system (in Spanish) for businesses that work by time slots — hair salons, gyms, workshops, tutors, studios, etc. It's a learning/demo project meant to showcase a complete Laravel build: MVC, auth, roles/permissions, simple multi-tenancy, availability rules, overlap prevention, a REST API, payments + webhooks, queues, notifications, scheduled tasks, real-time updates (Reverb), tests, Docker, and CI/CD.

`01-reservahub.md` is the authoritative spec — read it in full before implementing a feature area; the summary below only hits the parts most load-bearing for architecture decisions.

## Core domain architecture

**Multi-tenancy**: every tenant-owned table carries `business_id`. Every query must filter by the current business — there is no shared/global data across businesses except the `users` table pattern (a user's `business_id` is nullable, e.g. for platform-level accounts). Policies must prevent cross-business access; this is a primary test target ("employee no modifica otra empresa").

**Roles**: `owner`, `admin`, `employee`, `customer`. Spec suggests starting with a simple `role` column on `users` and migrating to granular permissions later — don't over-engineer permissions up front.

**Booking domain**: `bookings` reference `business_id`, `customer_id`, `employee_id`, `service_id`, `starts_at`/`ends_at`, and a `status` enum: `pending`, `confirmed`, `cancelled`, `completed`, `no_show`. Duration always comes from the `service`, never from client input.

**Availability engine** (Fase 4) is the core algorithm: given date + service + employee, it must combine the employee's weekly `schedules`, subtract `schedule_breaks`, subtract `time_offs`, subtract existing overlapping `bookings`, and account for the service's `buffer_minutes`, all in the business's `timezone` — then return free slots. This logic should be a dedicated `Services/AvailabilityService.php`, unit-tested independent of HTTP.

**Concurrency / overlap safety**: booking creation must re-validate availability *inside* a DB transaction (not just at the form-request layer) to prevent two simultaneous requests from double-booking the same employee/slot. This is explicitly called out as a required test scenario.

**Payments**: abstracted behind a `PaymentGateway` contract with a fake/test implementation and an optional real one. Webhook handling must be idempotent — `webhook_events.external_event_id` is unique per provider, and duplicate webhook deliveries must not duplicate a payment or double-confirm a booking. A booking with a required deposit (`deposit_amount`) stays `pending` until the payment is confirmed via webhook.

**Notifications**: booking confirmation, reschedule, cancellation, 24h/2h reminders, and employee alerts, over email + database channels (WhatsApp optional/simulated). Reminders must not be sent twice — dedupe when building the scheduled command that queues them.

## Planned code layout (from spec, Fase-driven)

```
app/
├── Actions/Bookings/     # CreateBooking, CancelBooking, ConfirmBooking, RescheduleBooking — one class per use case
├── Events/
├── Exceptions/
├── Http/Controllers/{Web,Api}/
├── Http/Requests/        # validates input
├── Http/Resources/       # JSON output shaping
├── Jobs/
├── Listeners/
├── Models/
├── Notifications/
├── Policies/             # authorization, esp. cross-business access checks
├── Services/             # AvailabilityService, Payments/*
└── Support/
```

Layering intent: Controller coordinates request/response only; Form Request validates; Policy authorizes; Action executes the use case (e.g. booking creation runs inside a transaction, re-checks availability, persists, fires events); Service holds reusable/integration logic (availability calculation, payment gateway); Model holds relations/casts/scopes only.

## API conventions

REST API under `/api/*`, authenticated via Sanctum (Fase 7). Every response follows:

```json
{
  "success": true,
  "data": {},
  "message": "Reserva creada correctamente.",
  "errors": null
}
```

## Key business rules to preserve in any implementation

- An employee cannot have two overlapping bookings.
- Booking duration is derived from the service, never client-supplied.
- Bookings must fall within working hours and respect breaks, holidays, and time off.
- Customers cannot cancel past the business's configured `cancellation_hours`.
- A repeated webhook must never duplicate a payment.
- A booking with a required deposit stays `pending` until payment is confirmed.
- Every query must be scoped by `business_id`.

## Real-time (Fase 9)

Laravel Reverb, one private channel per business, authorized via channel auth, used to push live calendar updates on booking events.
