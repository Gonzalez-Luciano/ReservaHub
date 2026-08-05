# Fase 0 — Bootstrap del proyecto ReservaHub

## Objetivo

Dejar el proyecto Laravel scaffolded y corriendo en dev, sin auth (eso es Fase 1), listo para empezar Fase 2 (tenancy) sobre una base limpia.

## Alcance

- Proyecto Laravel 12 nuevo, template plano (sin starter kit de auth).
- Inertia + React + Tailwind instalados manualmente (`php artisan install:inertia`, opción React), sin scaffolding de login.
- Entorno de desarrollo con **Laravel Sail**, template `pgsql` (Postgres + Redis + Mailpit).
- `.env.example` ajustado a la config de Sail/Postgres.
- App corre localmente vía `sail up`, migraciones default corren contra Postgres, página inicial React vía Inertia renderiza (placeholder).
- Git: fuera de alcance. El usuario maneja init/commits por su cuenta.

## Fuera de alcance (explícitamente, para no mezclar fases)

- Login/registro/verificación de email/reset password → Fase 1.
- Tablas `businesses`, middleware de tenancy → Fase 2.
- Cualquier modelo de dominio (services, bookings, etc.) → Fases 3+.
- Docker de producción (Dockerfile propio, no Sail) → Fase 10.
- CI/CD → más adelante, ligado a Fase 0 del spec original pero no bloqueante para arrancar a codear.

## Decisiones

| Decisión | Elegido | Alternativa descartada | Por qué |
|---|---|---|---|
| Frontend | Inertia + React + Tailwind | Blade puro / Livewire | Preferencia del usuario; encaja con Fase 9 (Reverb) para UI reactiva |
| Auth scaffolding | Ninguno en Fase 0 | Starter kit React (trae auth incluido) | Mantiene Fase 0 y Fase 1 separadas, como indica el spec original |
| DB dev | PostgreSQL | SQLite, MySQL | Pedido explícito del usuario |
| Docker | Laravel Sail (`pgsql` template) | docker-compose manual | Sail ya resuelve esto, mantenido oficialmente; Docker de producción real se arma a propósito en Fase 10 sin duplicar esfuerzo ahora |

## Resultado esperado (criterio de "listo")

1. `./vendor/bin/sail up -d` levanta app + postgres + redis + mailpit sin error.
2. `sail artisan migrate` corre contra Postgres sin error.
3. `sail npm run dev` (o build) sirve una página React vía Inertia en `/`.
4. `.env.example` contiene las vars correctas de conexión a Postgres/Sail.
5. `composer.json` / `package.json` reflejan Laravel 12 + Inertia + React + Tailwind.

## Testing

Nada de lógica de negocio todavía — no aplica TDD. Verificación es manual: los 4 puntos de "resultado esperado" arriba, confirmados corriendo los comandos.
