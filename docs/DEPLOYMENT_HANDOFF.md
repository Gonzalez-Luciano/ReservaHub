# Handoff de despliegue — ReservaHub

Contrato de **aplicación** para quien opere el servidor. No es un manual de administración de Linux: no indica distro, rutas del host, puertos, tunnel, firewall ni backups del sistema. Esas decisiones son del workflow externo de operaciones de home server, que las toma después de inspeccionar la máquina real.

- Autoridad sobre la aplicación: este repositorio (`01-reservahub.md`, `CLAUDE.md`, este documento).
- Autoridad sobre el servidor físico: el workflow externo de operaciones (`/srv/apps`, `/srv/backups`, registro de puertos, `cloudflared`, secretos, backups, deploy, rollback).

## 1. Qué es

SaaS de reservas por turnos, multi-tenant (`business_id` en toda tabla de negocio). **Un solo runtime de aplicación**: Laravel 13 + Inertia + React compilado por Vite. No hay servicio Node en producción ni frontend separado; los assets se compilan a `public/build` y los sirve el mismo proceso web.

```text
Laravel 13 / Inertia / React (un solo servicio HTTP)
        |
PostgreSQL 18   (datos, sesiones, cache, locks del scheduler)
        |
Redis           (cola de trabajos)
        |
worker de cola + scheduler (procesos aparte, mismo código)
```

Es **un proyecto Docker aislado**: red, volúmenes, base de datos, Redis y credenciales propios. Lo único que comparte con otros proyectos del host es el Docker Engine, el `cloudflared` del host y el sistema operativo.

## 2. Procesos que la aplicación necesita

| Proceso | Comando | Obligatorio | Notas |
|---|---|---|---|
| Web/app | servidor PHP-FPM/HTTP sirviendo `public/` | Sí | Único entrypoint HTTP. Document root: `public/` |
| Worker de cola | `php artisan queue:work --tries=3 --max-time=3600` | Sí | Sin él no sale ningún email (notificaciones de reserva, recordatorios, verificación, reset de contraseña, invitaciones) |
| Scheduler | `php artisan schedule:work` (o `schedule:run` por cron cada minuto) | Sí | Ejecuta `bookings:send-reminders` cada 5 minutos |

El worker mantiene el código en memoria: hay que reiniciarlo en cada deploy (`queue:restart` o reinicio del contenedor).

Referencia de desarrollo: `compose.yaml` del repo (Sail) ya define `laravel.test`, `queue`, `scheduler`, `pgsql`, `redis` y `mailpit`. Sirve como descripción de la topología; **no es un compose de producción** (publica puertos al host, monta el código como volumen e incluye Mailpit).

## 3. Servicios de datos

### PostgreSQL 18 — **persistente, obligatorio**

Contiene todo el estado del negocio: `businesses`, `users`, `services`, `schedules`, `schedule_breaks`, `time_offs`, `bookings`, `booking_status_histories`, `booking_reminders`, `employee_invitations`, `notifications`, `personal_access_tokens`, más `sessions`, `cache` y `jobs` del framework.

- Es el único dato que **no** se puede perder.
- Requiere volumen persistente y backup (ver §8).

### Redis — obligatorio en runtime, persistencia opcional

Uso único: transporte de la cola (`QUEUE_CONNECTION=redis`). Sesiones, cache y locks del scheduler van a PostgreSQL, no a Redis.

- Perder Redis pierde solo los trabajos encolados y no ejecutados (emails pendientes). No corrompe datos de negocio.
- Persistencia (AOF/RDB) es deseable pero no crítica; no es candidato a backup.

### Correo saliente — obligatorio para el flujo funcional

SMTP real en producción (`MAIL_*`). Mailpit es solo de desarrollo y no debe desplegarse.

## 4. Contrato de entorno

Nombres de variables, no valores. **Este repositorio no contiene ni debe contener valores de producción.** El `.env` de producción lo crea y lo custodia el operador del servidor, fuera de git. `.env.example` es la plantilla de referencia.

| Variable | Dueño | Secreto | Nota |
|---|---|---|---|
| `APP_NAME` | app | no | |
| `APP_ENV` | operador | no | `production` |
| `APP_KEY` | operador | **sí** | Generar una vez con `php artisan key:generate`; si cambia, se invalidan sesiones y datos cifrados |
| `APP_DEBUG` | operador | no | `false` en producción, sin excepciones |
| `APP_URL` | operador | no | URL pública real; de ella dependen los links de emails (verificación, reset, invitaciones) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | app | no | `es` / `en` |
| `DB_CONNECTION` | app | no | `pgsql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` | operador | no | `DB_HOST` = nombre del servicio de la red Docker del stack, nunca `127.0.0.1` del host |
| `DB_PASSWORD` | operador | **sí** | |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_CLIENT` | operador | no | Servicio de la red interna del stack |
| `REDIS_PASSWORD` | operador | **sí** si se usa | |
| `QUEUE_CONNECTION` | app | no | `redis` |
| `CACHE_STORE` | app | no | `database` |
| `SESSION_DRIVER` | app | no | `database` |
| `SESSION_SECURE_COOKIE` | operador | no | `true` detrás de HTTPS |
| `BROADCAST_CONNECTION` | app | no | `log` hasta que exista la Fase 10 (Reverb) |
| `FILESYSTEM_DISK` | app | no | `local` |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` / `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | operador | no | SMTP real |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | operador | **sí** | |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` | operador | no | Ver §7 |
| `TRUSTED_PROXIES` | operador | no | Necesario detrás de proxy/tunnel — ver §10 |

### `SESSION_DRIVER=database` — requisito operativo

Deja de ser una conveniencia. La aplicación invalida las sesiones ajenas
borrando filas de la tabla `sessions`, y con cualquier otro driver
`UserAccessRevoker` lanza una excepción: el cambio de contraseña y la
desactivación de usuarios fallarían con 500 en producción. La tabla `sessions`
tiene que estar presente y migrada (ya viene en las migraciones base).

## 5. Build

Sin proceso Node en runtime; el build se hace antes de servir (en la imagen o en el deploy):

```bash
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
pnpm install --frozen-lockfile
pnpm build            # genera public/build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

- El gestor de paquetes JS es **pnpm** (`pnpm-lock.yaml`); no existe `package-lock.json`.
- Si `public/build` falta, **toda página Inertia falla** (`Not a valid Inertia response`). Es un fallo de deploy, no un bug de la app.
- `--no-dev` excluye Scramble (documentación OpenAPI), que es dependencia de desarrollo.
- Si existiera `public/hot` (artefacto del dev server de Vite), borrarlo: hace que la app apunte a un servidor Vite inexistente.

## 6. Migración y bootstrap

```bash
php artisan migrate --force                    # migración, idempotente, obligatoria en cada deploy
php artisan db:seed --class=DemoSeeder         # datos de demo, opcional, idempotente
```

- Las migraciones no destruyen datos; no hay migraciones `down` pensadas para rollback en caliente.
- **Nunca** `migrate:fresh`, `migrate:refresh` ni `db:wipe` sobre datos que deban persistir.
- `DemoSeeder` es seguro y repetible: si ya existe el negocio `peluqueria-demo` no hace nada. Hoy crea la empresa demo, un owner, dos empleados, cinco servicios y horarios de lunes a viernes (todavía no siembra clientes ni reservas). Sin datos reales de clientes ni de pagos.
- **No usar `db:seed` sin `--class`**: el `DatabaseSeeder` por defecto crea además un usuario de prueba `test@example.com`.
- Las credenciales de demo (`owner@reservahub.test`) son públicas por diseño. Si la instancia es accesible desde internet, la contraseña de demo debe cambiarse tras el seed o gestionarse desde el entorno.

## 7. Salud, smoke checks y logs

**Health:** `GET /up` — endpoint de health de Laravel (arranca el framework; falla si falta `APP_KEY`, la config está rota o la app no bootea). Es el check apto para orquestación. No verifica la base de datos.

**Smoke tras deploy:**

```bash
curl -fsS  https://HOST/up                       # 200
curl -fsSI https://HOST/                         # portada pública responde
curl -fsS  -X POST https://HOST/api/auth/login \
  -H 'Accept: application/json' \
  -d 'email=...&password=...&device_name=smoke'  # 200 con {success:true,...,data.token}
```

Un token válido permite además `GET /api/services` y `GET /api/availability` (ver `docs/api.md`). Señales de que el deploy salió bien: `/up` en 200, una página Inertia que renderiza (assets presentes), login de API devolviendo token, worker consumiendo la cola y `php artisan schedule:list` mostrando `bookings:send-reminders`.

**Logs:** canal `stack`/`single` → `storage/logs/laravel.log` dentro del contenedor de la app. El worker y el scheduler escriben además a stdout del proceso. Nivel por `LOG_LEVEL`. Los logs no requieren backup; conviene rotarlos.

## 8. Datos persistentes y backup

| Dato | Persistente | Backup | Por qué |
|---|---|---|---|
| Volumen de PostgreSQL | **Sí** | **Sí** | Único dato irrecuperable |
| `storage/app` | Sí | No, por ahora | La app no sube archivos y no está previsto que lo haga: el logo es un asset fijo del frontend y `businesses.logo_path` queda sin uso a propósito (§2 del roadmap). Si algún día se agregan uploads, este directorio pasa a ser dato de usuario y entra al backup |
| `storage/logs` | Conveniente | No | Diagnóstico |
| `storage/framework/{cache,views,sessions}` | No | No | Regenerable; sesiones y cache viven en PostgreSQL |
| Volumen de Redis | Opcional | No | Solo trabajos encolados |
| `.env` de producción | Sí | Sí, en el gestor de secretos del operador | Nunca en git |
| `public/build` | No | No | Se regenera con `pnpm build` |

Información relevante para rollback: volver a un commit anterior es seguro mientras el esquema no haya avanzado. Si el deploy incluyó migraciones, el rollback de código **no** revierte el esquema; hay que restaurar desde backup de base de datos o aplicar una migración correctiva. Cada deploy debería registrar el commit desplegado y si aplicó migraciones.

## 9. Qué no debe exponerse nunca

- PostgreSQL y Redis: solo en la red interna del stack, sin puertos publicados.
- Mailpit: no desplegar en producción.
- El puerto del dev server de Vite (5173): no existe en producción.
- `/docs/api` (OpenAPI de Scramble): dependencia de desarrollo, restringida a `local`; con `composer install --no-dev` ni siquiera se registra.
- `APP_DEBUG=true` en un entorno accesible: filtra entorno y stack traces.
- `.env`, `APP_KEY`, credenciales de base de datos, de Redis y de SMTP, y tokens de Sanctum.
- El `compose.yaml` del repo publica puertos al host (`80`, `5432`, `6379`, `1025`, `8025`, `5173`) porque es de desarrollo: **no reutilizarlo tal cual en producción**.

## 10. Asunciones de la aplicación que el operador debe cubrir

- **Proxy inverso / tunnel:** la app todavía no configura proxies de confianza. Detrás de un proxy que termina TLS hay que fijar `TRUSTED_PROXIES` (o equivalente) y `APP_URL` con `https://`, o los links generados en emails y redirecciones saldrán con esquema o host incorrectos.
- **Zona horaria:** la app opera en la zona horaria de cada negocio (`businesses.timezone`) y persiste las reservas en UTC. El host puede quedar en UTC.
- **HTTPS:** se asume terminación TLS delante de la app.
- **Reloj:** el scheduler y los recordatorios dependen de un reloj correcto en el host.
- **Un solo worker es suficiente** para la carga de demo; escalar horizontalmente es seguro (los recordatorios se deduplican en la tabla `booking_reminders` y la creación de reservas usa advisory locks de PostgreSQL).
