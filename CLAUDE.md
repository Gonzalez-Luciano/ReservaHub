# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

This repo currently contains only the design spec (`01-reservahub.md`) — no Laravel application has been scaffolded yet, and this is not (yet) a git repository. Before writing code, check whether the project has since been scaffolded (look for `composer.json`, `artisan`); if not, follow Fase 0 in the spec to bootstrap it:

```bash
composer create-project laravel/laravel reservahub
cd reservahub
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Once scaffolded, standard Laravel commands apply: `php artisan test` (or a specific test with `php artisan test --filter=TestName`), `vendor/bin/pint --test` for formatting, `php artisan serve`, `npm run dev`.

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
