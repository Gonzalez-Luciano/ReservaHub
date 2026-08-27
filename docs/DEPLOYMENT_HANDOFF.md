# Handoff de despliegue — ReservaHub

Contrato de **aplicación** para quien opere el servidor. No es un manual de administración de Linux: no indica distro, usuario del servidor, SSH, firewall, estructura física de `/srv`, reverse proxy del host, Cloudflare, DNS, certificados ni backups del sistema operativo. Esas decisiones son del **agente de operaciones del VPS**, que las toma después de inspeccionar la máquina real.

- **Autoridad sobre la aplicación:** este repositorio (`01-reservahub.md`, `CLAUDE.md`, este documento).
- **Autoridad sobre el servidor físico:** el agente de operaciones del VPS.

El destino conceptual es un **VPS Linux multiproyecto**, inicialmente previsto en **OVHcloud**. Este documento explica **ReservaHub** — sus imágenes, sus contenedores, su contrato de entorno, sus procedimientos de migración/siembra/reset/salud/rollback — no cómo administrar Linux. La lista completa de qué entrega este repositorio y qué decide operaciones está en §21.

> Modelo anterior (superado): versiones previas de este documento describían un despliegue de **home server** (`/srv/apps`, `cloudflared`, Docker Engine compartido con otros proyectos del mismo host físico) con **reinicio diario completo** de la demo. Ambos quedaron reemplazados por la Fase 12: el destino es un VPS Linux multiproyecto y el reset completo de la demo es **semanal** (§12, §15); solo la restauración de credenciales sigue siendo diaria (§13).

## 1. Qué es y qué NO es este documento

SaaS de reservas por turnos, multi-tenant (`business_id` en toda tabla de negocio). **Un solo runtime de aplicación**: Laravel 13 + Inertia + React compilado por Vite. No hay servicio Node en producción ni frontend separado; los assets se compilan a `public/build` en tiempo de build y viajan dentro de la imagen (§2, §6).

```text
Nginx (web)  →  PHP-FPM (app)  →  Laravel 13 / Inertia / React
                     |
              PostgreSQL 18   (datos, sesiones, cache, locks del scheduler)
                     |
              Redis           (cola de trabajos)
                     |
      worker de cola + scheduler + Reverb  (mismo código, comandos distintos)
                     |
              Mailpit         (buzón público de la demo)
```

Es lo que este repositorio entrega: el Dockerfile productivo, el compose productivo, las imágenes en GHCR, el contrato de entorno, las migraciones, `DemoSeeder`, `demo:reset`, `demo:restore-access`, los healthchecks, los smoke checks y este mismo documento. No decide distro, SSH, firewall, `/srv`, reverse proxy del host, Cloudflare, DNS, secretos reales, hostname real, certificados, scheduling real del host, retención real de Mailpit, reboot recovery, backups del host, ni ejecuta el primer deployment real — eso es del agente de operaciones del VPS (lista completa en §21).

## 2. Qué imágenes existen

Dos imágenes públicas en GitHub Container Registry, publicadas por `.github/workflows/release.yml` en cada tag `v*`:

```text
ghcr.io/gonzalez-luciano/reservahub-app
ghcr.io/gonzalez-luciano/reservahub-web
```

Cada una con tres tags por release:

```text
X.Y.Z          — versión semántica (el tag de git sin el prefijo "v")
sha-<commit>   — trazabilidad exacta al commit
latest         — conveniencia, NO una referencia de deployment
```

**Producción fija versión o digest; nunca `latest`.** El propio workflow de release lo deja escrito en el resumen del run: *"Fijar el digest en producción. No desplegar por `latest`."* — `latest` es cómodo para explorar la imagen manualmente, pero apuntar producción a una etiqueta móvil significa que un `docker compose pull` sin cambiar nada más puede traer un release distinto sin que nadie lo pidiera.

`reservahub-web` se construye a partir de la imagen de `reservahub-app` recién publicada (`build-args: APP_IMAGE=...`), para que ambas contengan literalmente el mismo `public/build` — no hay forma de que el frontend servido por Nginx y el que conocería PHP-FPM diverjan entre sí dentro del mismo release.

## 3. Qué contenedores ejecutar

Ocho servicios, definidos en `compose.production.yaml`. **`app`, `queue`, `scheduler` y `reverb` corren la misma imagen** (`reservahub-app`) y solo cambian de comando — no hay cuatro imágenes distintas que mantener sincronizadas, hay una imagen y cuatro procesos.

| Servicio | Imagen | Comando |
|---|---|---|
| `web` | `reservahub-web` | Nginx, único borde HTTP |
| `app` | `reservahub-app` | PHP-FPM (sin comando explícito, el `CMD` de la imagen) |
| `queue` | `reservahub-app` | `php artisan queue:work --tries=3 --max-time=3600` |
| `scheduler` | `reservahub-app` | `php artisan schedule:work` |
| `reverb` | `reservahub-app` | `php artisan reverb:start --host=0.0.0.0 --port=8080` |
| `pgsql` | `postgres:18-alpine` | — |
| `redis` | `redis:alpine` | `redis-server --appendonly yes` |
| `mailpit` | `axllent/mailpit:latest` | — |

`--tries=3 --max-time=3600` en `queue` recicla el worker cada hora, lo que acota cualquier fuga de memoria de un proceso de larga vida — y por eso mantiene el código en memoria: cada deploy necesita recrear este contenedor, no solo el de `app`.

### Riesgo de nombre de proyecto Docker Compose — ya corregido, no revertir

`compose.production.yaml` declara explícitamente:

```yaml
name: reservahub-production
```

**Esto no es cosmético.** `docker compose` deriva el nombre de proyecto por defecto del basename del directorio desde el que se ejecuta, y este repositorio se llama `reservahub` — el nombre natural para clonarlo. Un checkout de desarrollo (Sail, `compose.yaml`, sin `name:` explícito) corrido desde un directorio `reservahub/` resuelve **al mismo** project name por defecto, `reservahub`, con contenedores `reservahub-pgsql-1` / `reservahub-redis-1` / `reservahub-mailpit-1`.

Esto no es hipotético: durante la Tarea 12, la primera verificación local de este mismo compose productivo — corrida antes de que existiera esta línea `name:` — **recreó (destruyó) los contenedores del stack de desarrollo del checkout principal** porque compartían esos mismos nombres de contenedor bajo el project name `reservahub`. Los datos no se perdieron (los volúmenes con nombre de cada stack son distintos), pero los contenedores sí, y el stack de desarrollo quedó abajo hasta reconstruirlo a mano.

**Si algún operador futuro "prolija" este archivo y vuelve a poner `name: reservahub`** (por ejemplo para que coincida con el nombre del repo, o con el de la imagen), reintroduce exactamente este riesgo en cualquier máquina donde este compose productivo se corra alguna vez desde un directorio también usado para desarrollo — incluida la propia verificación local que hizo evidente el bug. El nombre `reservahub-production` es la corrección, no un detalle de estilo.

## 4. Qué procesos son obligatorios

| Proceso | Obligatorio | Consecuencia si falta |
|---|---|---|
| `web` | Sí | Único entrypoint HTTP; sin él no hay aplicación |
| `app` | Sí | PHP-FPM; `web` no tiene a quién reenviar |
| `queue` | Sí | Sin él no sale **ningún** email (confirmación, recordatorios, verificación, reset de contraseña, invitaciones) |
| `scheduler` | Sí | Sin él dejan de correr los recordatorios, la expiración de señas, la reconciliación de pagos, la restauración diaria de acceso y el reset semanal de la demo |
| `reverb` | Solo para tiempo real | Sin él la aplicación funciona **entera**; solo deja de refrescarse sola la pantalla de reservas (`router.reload` manual sigue trayendo el estado correcto) |

## 5. Qué puertos internos existen

| Puerto | Servicio | Protocolo |
|---|---|---|
| `8080` | `web` | HTTP |
| `9000` | `app` | FastCGI |
| `8080` | `reverb` | HTTP / WebSocket (namespace de red propio del contenedor; no colisiona con el `8080` de `web`) |
| `5432` | `pgsql` | PostgreSQL |
| `6379` | `redis` | Redis |
| `1025` | `mailpit` | SMTP |
| `8025` | `mailpit` | HTTP (dashboard) |

**Solo `web` y `mailpit` se publican al host** (verificado contra `compose.production.yaml`: son los dos únicos bloques `ports:` del archivo). PostgreSQL y Redis nunca tienen un `ports:` — solo la red interna `reservahub`. Los dos puertos publicados están además atados a `127.0.0.1` por defecto (`WEB_BIND`, `MAILPIT_BIND`), así que ni siquiera con el puerto publicado quedan alcanzables desde fuera del propio VPS sin que el operador lo decida explícitamente (más detalle de esta cadena de confianza en §8).

## 6. Qué debe persistir

| Dato | Volumen | Persistente | Backup | Por qué |
|---|---|---|---|---|
| PostgreSQL | `pgsql-data` | **Sí** | **Sí** | **Único dato irrecuperable**: `businesses`, `users`, `services`, `schedules`, `bookings`, `payments`, sesiones, cache y locks del scheduler |
| Redis | `redis-data` | Sí (AOF) | No | Solo trabajos encolados y no ejecutados (emails pendientes); perderlo no corrompe datos de negocio, solo retrasa/pierde una notificación |
| Mailpit | `mailpit-data` | Sí | No | Conveniente, no crítico: es el buzón descartable de la demo |
| `storage/app` | — (sin volumen) | No aplica | No | La aplicación **no acepta uploads**: `businesses.logo_path` existe en el esquema pero no se usa (el logo es un asset fijo del frontend). Si algún día se agregan uploads, este directorio pasa a ser dato de usuario y necesita volumen + backup |
| `public/build` | — (dentro de la imagen) | No aplica | No | Viaja horneado en `reservahub-web`/`reservahub-app`; se regenera con un build nuevo, no con un volumen |

## 7. Contrato de entorno completo

Nombres de variables, no valores — **este repositorio no contiene ni debe contener un solo valor real de producción**. `.env.production.example` es la plantilla; el operador crea y custodia el `.env` real fuera de git.

Categorías: `secret` (nunca se publica, nunca entra a una imagen, nunca a git) · `runtime público` (se lee al arrancar el proceso) · `build-time público` (se **compila** dentro del bundle) · `internal` (nombres de servicio de la red interna del stack) · `development-only` (no va en producción).

| Variable | Categoría | Nota |
|---|---|---|
| `COMPOSE_ENV_FILE` | internal | Se declara a sí mismo — ver comentario en `.env.production.example` sobre por qué esta variable tiene que coincidir con el nombre real del archivo |
| `APP_NAME` | runtime público | |
| `APP_ENV` | runtime público | `production` |
| `APP_KEY` | **secret** | Generar UNA vez con `php artisan key:generate --show`; cambiarla invalida sesiones y datos cifrados |
| `APP_DEBUG` | runtime público | `false` en producción, sin excepciones |
| `APP_URL` | runtime público | URL pública real con `https://`; de ella dependen los links de emails |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | runtime público | `es` / `en` |
| `DB_CONNECTION` | runtime público | `pgsql` |
| `DB_HOST` | internal | nombre del servicio (`pgsql`), nunca `127.0.0.1` |
| `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` | runtime público | |
| `DB_PASSWORD` | **secret** | |
| `REDIS_HOST` / `REDIS_PORT` | internal | |
| `REDIS_PASSWORD` | **secret** si se usa | Vacío = sin auth; solo aceptable porque Redis nunca se publica (§5) |
| `QUEUE_CONNECTION` | runtime público | `redis` — la cola es Redis, no la tabla `jobs` |
| `CACHE_STORE` | runtime público | `database` |
| `SESSION_DRIVER` | runtime público | `database` — **obligatorio**, ver recuadro abajo |
| `SESSION_LIFETIME` | runtime público | |
| `SESSION_SECURE_COOKIE` | runtime público | `true` detrás de HTTPS |
| `FILESYSTEM_DISK` | runtime público | `local` |
| `TRUSTED_PROXIES` | runtime público | `*` — ver la cadena de confianza del proxy en §8 |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` | runtime público | Ver §16 |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` | runtime público / internal | `MAIL_HOST=mailpit` es la configuración **correcta** en el modelo de demo pública, no una salvedad de desarrollo (§14, `01-reservahub.md` §11.5); SMTP real contra un proveedor externo es igual de válido si la instancia deja de presentarse como demo |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | **secret** | Vacío con Mailpit |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | runtime público | |
| `BROADCAST_CONNECTION` | runtime público | `reverb` |
| `REVERB_APP_ID` | runtime público | |
| `REVERB_APP_KEY` | runtime público | Público por protocolo: viaja al navegador |
| `REVERB_APP_SECRET` | **secret** | Firma servidor→Reverb. **Nunca** en una `VITE_*` |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | internal | Dónde encuentra el **servidor** a Reverb — ver §8 |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | internal | Dónde **escucha** el proceso — ver §8 |
| `REVERB_ALLOWED_ORIGINS` | runtime público | Solo host, sin esquema ni puerto; admite comodines `*`. Vacío = solo `localhost`: falla cerrado |
| `REVERB_SCALING_ENABLED` | runtime público | `false` — una sola instancia |
| `VITE_REVERB_APP_KEY` / `VITE_REVERB_HOST` / `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | **build-time público** | Dónde encuentra el **navegador** a Reverb — ver §8 y advertencia abajo |
| `VITE_DEMO_MAIL_URL` | **build-time público** | URL pública del buzón de la demo; sin definir, el CTA del buzón simplemente no se renderiza |
| `PAYMENTS_SIMULATED_WEBHOOK_SECRET` | **secret** | Sin ella la app falla al arrancar el binding de `PaymentGateway` — falla cerrado, a propósito |
| `PAYMENTS_WINDOW_MINUTES` / `PAYMENTS_WEBHOOK_TOLERANCE_SECONDS` / `PAYMENTS_RECONCILE_BATCH` / `PAYMENTS_RECONCILE_CADENCE_MINUTES` | runtime público | |
| `DEMO_PUBLIC_MODE` | runtime público | `true` habilita `demo:reset` — ver §12 |
| `DEMO_TARGET_DATABASE` | runtime público | Debe coincidir EXACTAMENTE con `DB_DATABASE` — segunda guarda independiente, ver §12 |
| `DEMO_ACCOUNT_PASSWORD` | runtime público | Pública por definición: se publica en `/como-funciona` |

**Dos advertencias que no son opcionales:**

- **Las `VITE_*` se compilan dentro del bundle.** Cambiarlas exige una imagen nueva y una release nueva; reiniciar contenedores no hace nada, porque `public/build` viaja horneado en la imagen (§2, §6). Esto incluye `VITE_REVERB_*` y `VITE_DEMO_MAIL_URL`.
- **`SESSION_DRIVER=database` es obligatorio, no una preferencia.** Con otro driver, `App\Support\UserAccessRevoker` lanza `UnsupportedSessionDriverException`: el cambio de contraseña, la desactivación de usuarios y `demo:restore-access` fallarían con 500 en producción, porque los tres invalidan sesiones ajenas borrando filas de la tabla `sessions`.

## 8. Las tres direcciones de Reverb y la cadena de confianza del proxy

**Tres pares de direcciones que no hay que confundir**, porque ninguno reemplaza a otro:

`REVERB_HOST`/`REVERB_PORT` es dónde el **servidor** (la aplicación, el worker de cola) encuentra a Reverb — un nombre de servicio de la red interna del stack. `VITE_REVERB_HOST`/`VITE_REVERB_PORT` es dónde lo encuentra el **navegador** — el host público, compilado dentro del bundle. `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` es dónde el propio proceso **escucha** (`0.0.0.0` y el puerto interno).

**La cadena de confianza del proxy inverso**, tan concreta como lo anterior porque decide si las cookies seguras y las URLs firmadas funcionan: `web` (Nginx) es el único borde HTTP; `bootstrap/app.php` confía en él vía `TRUSTED_PROXIES=*` porque `app` no tiene otro llamante posible en la red interna (el puerto 9000 nunca se publica). `web` a su vez conserva el `X-Forwarded-Proto` que ya traiga una petición y solo lo calcula de su propia conexión cuando no viene nada (`map` en `default.conf`) — necesario porque el destino real tiene un proxy externo que termina TLS y reenvía HTTP simple hacia este contenedor; `$scheme` de Nginx por sí solo siempre diría `http` en ese caso. Lo que hace esto seguro sin conocer al proxy real: el default `WEB_BIND=127.0.0.1` (§5) — nada en internet puede ser el peer de `web`, solo un proceso del mismo VPS. **Si el operador expone `web` directamente** (`WEB_BIND=0.0.0.0`, sin un proxy de por medio), sanitizar `X-Forwarded-*` antes de que lleguen acá pasa a ser su responsabilidad — este repositorio ya no puede garantizarlo.

**Proxy con soporte de WebSocket:** el entrypoint público (fuera de este repositorio, decisión de operaciones) tiene que distinguir tres rutas y dos destinos:

| Ruta | Destino | Protocolo |
|---|---|---|
| `/app/*` | Reverb | WebSocket: requiere `Upgrade`/`Connection: Upgrade` y HTTP/1.1 |
| `/apps/*` | Reverb | HTTP normal (API de publicación del protocolo Pusher) |
| `/broadcasting/auth` | Aplicación Laravel | HTTP normal, autenticado por sesión |
| todo lo demás | Aplicación Laravel | HTTP normal |

La autorización de canal privado sigue siendo una petición HTTP de la aplicación con cookie de sesión, no tráfico de Reverb. Preferencia arquitectónica: una sola frontera pública de ReservaHub capaz de servir HTTP y de hacer upgrade a WebSocket — Reverb es un proceso interno, no una segunda aplicación pública.

## 9. Cómo migrar

```bash
docker compose -f compose.production.yaml exec app php artisan migrate --force
```

Idempotente, **obligatoria en cada deploy**. **Nunca** `migrate:fresh`, `migrate:refresh` ni `db:wipe` sobre datos que deban conservarse — esos tres comandos sí se usan, a propósito, dentro de `demo:reset` (§12), donde borrar la base entera es la operación deseada.

## 10. Cómo arrancar

Procedimiento manual, en nueve pasos:

1. **Elegir release** — un tag `vX.Y.Z` concreto (§2). Nunca `latest`.
2. **Obtener imágenes** — `docker compose -f compose.production.yaml --env-file .env pull`.
3. **Configurar entorno** — copiar `.env.production.example` a `.env`, completar los valores marcados `secret` y `runtime público` (§7). No omitir `COMPOSE_ENV_FILE` (debe apuntar a este mismo archivo).
4. **Levantar infraestructura** — `docker compose -f compose.production.yaml --env-file .env up -d pgsql redis mailpit`, esperar a que los tres healthchecks reporten `healthy` (§16).
5. **Ejecutar migraciones** — §9.
6. **Iniciar runtime** — `docker compose -f compose.production.yaml --env-file .env up -d`, esperar `web`/`app`/`reverb` en `healthy`.
7. **Ejecutar bootstrap demo si corresponde** — `db:seed --class=DemoSeeder --force` (§11); omitir si la instancia no es demo pública.
8. **Ejecutar smoke** — `scripts/smoke.sh <base-url>` (§17).
9. **Verificar logs** — sin errores de arranque en `app`/`queue`/`scheduler`/`reverb` (§16).

Este procedimiento lo ejecutará después el agente de operaciones sobre el VPS real; la Fase 12 no despliega todavía. Solo después de que el deployment manual haya demostrado ser reproducible se evaluará CD automático — no hay, ni debe agregarse aquí, un GitHub Action que haga SSH al servidor.

## 11. Cómo sembrar

```bash
docker compose -f compose.production.yaml exec app php artisan db:seed --class=DemoSeeder --force
```

**Nunca `db:seed` a secas**: `DatabaseSeeder` por defecto crea además un usuario de conveniencia `test@example.com`, ajeno al dataset de demo. `DemoSeeder` es idempotente por slug de negocio (`peluqueria-demo`, `estudio-demo`): volver a correrlo no duplica nada, pero tampoco es la operación pensada para "reiniciar" la demo — eso es `demo:reset` (§12).

## 12. Cómo ejecutar `demo:reset`

```bash
docker compose -f compose.production.yaml exec app php artisan demo:reset --force
```

**Borra la base entera** (`migrate:fresh` + `DemoSeeder`, nunca `DatabaseSeeder`) y vacía la cola de Redis antes y después del reset, para que ningún job pendiente opere sobre IDs que ya no existen. `--force` es obligatorio en ejecución no interactiva (sin una terminal real, el comando aborta sin pedir confirmación por stdin).

Tres guardas independientes, todas en `App\Support\DemoEnvironment`, cualquiera que falle produce `ABORT` sin tocar un solo dato:

1. `DEMO_PUBLIC_MODE=true` — declara la intención. `APP_ENV=production` no participa de esta guarda: una base productiva de verdad también tiene `APP_ENV=production`.
2. `DEMO_TARGET_DATABASE` coincide exactamente con el nombre real de la base conectada — segunda confirmación no booleana; un `.env` copiado a otra instancia se delata acá.
3. La base, si ya tiene la tabla `businesses`, contiene al menos uno de los slugs canónicos (`peluqueria-demo`, `estudio-demo`) — una base sin `businesses` es un primer arranque legítimo; una que la tiene pero no reconoce ningún slug de demo no se toca.

Programación: **lunes 00:00 `America/Argentina/Buenos_Aires`** (§15).

## 13. Cómo ejecutar `demo:restore-access`

```bash
docker compose -f compose.production.yaml exec app php artisan demo:restore-access
```

Para cada cuenta publicada en `config/demo.accounts` (localizada por email, o por `business_slug`+rol si un visitante le cambió el email al owner compartido): restaura email, contraseña (`DEMO_ACCOUNT_PASSWORD`), `is_active=true` y `email_verified_at`; corta los tres vectores de re-autenticación de quien haya tomado la cuenta vía `UserAccessRevoker` (`remember_token`, tokens de Sanctum, filas de `sessions`); y borra cualquier `password_reset_tokens` pendiente para el email nuevo y el viejo.

**Qué NO toca:** reservas, pagos, servicios, horarios, historial — nada del dataset funcional de la semana en curso. Es exclusivamente restauración de acceso, no un reset de datos.

Diaria, **00:00** (misma zona horaria, §15). No pide `--force`: corre desatendida todos los días y no destruye datos funcionales, aunque comparte las mismas guardas de `DemoEnvironment` que `demo:reset`.

## 14. Cómo limpiar Mailpit

Responsabilidad de **operaciones**, no de este repositorio: diaria, a las 00:00 `America/Argentina/Buenos_Aires`. `demo:reset` **no** la llama — es otro servicio, con su propio ciclo de vida, y limpiarlo no es parte del contrato de la aplicación.

`MP_MAX_MESSAGES` (`MAILPIT_MAX_MESSAGES`, por defecto `2000` en `compose.production.yaml`) es una retención **complementaria**, no un reemplazo: acota cuánto puede crecer el buzón entre limpiezas, pero no sustituye la limpieza diaria real que decide y ejecuta operaciones.

## 15. El contrato de scheduling

```text
SEMANAL   lunes 00:00 America/Argentina/Buenos_Aires  → demo:reset
DIARIO    00:00       America/Argentina/Buenos_Aires  → demo:restore-access
DIARIO    00:00       America/Argentina/Buenos_Aires  → limpiar Mailpit
```

Verificado contra `routes/console.php` y `php artisan schedule:list` en este mismo checkout:

```text
*/5 * * * *  php artisan bookings:send-reminders
*/5 * * * *  php artisan bookings:expire-unpaid
*/5 * * * *  php artisan payments:reconcile
0   0 * * *  php artisan demo:restore-access     [America/Argentina/Buenos_Aires]
0   0 * * 1  php artisan demo:reset --force      [America/Argentina/Buenos_Aires]
```

`demo:reset` y `demo:restore-access` ya están **dentro** del scheduler de Laravel (`routes/console.php`), así que basta con mantener vivo el contenedor `scheduler` (§4) — el host **no** necesita cron propio para esos dos. Solo la limpieza de Mailpit (§14) queda fuera del scheduler de Laravel y sí es responsabilidad de un cron/systemd timer del lado de operaciones.

**Advertencia de deriva:** `resources/js/Components/domain/DemoResetCountdown.jsx` le promete al visitante exactamente el horario semanal de arriba (constante de módulo `RESET_HOUR = 0`, zona horaria de demo, no una variable de entorno — así que ese archivo requiere recompilar el frontend si el horario real cambia alguna vez). Un desfase entre lo que el contador promete y lo que el scheduler ejecuta rompe la confianza del visitante en la demo, aunque no rompa ninguna regla de negocio.

## 16. Cómo comprobar salud

Healthchecks reales, tal como están declarados en `compose.production.yaml`:

| Servicio | Chequeo | Verificado |
|---|---|---|
| `web` | `wget -qO- http://127.0.0.1:8080/up` | Atraviesa Nginx → PHP-FPM → Laravel a propósito: es el health de la cadena completa, no un ping a Nginx. `127.0.0.1`, no `localhost` — Nginx solo escucha IPv4 y `/etc/hosts` resuelve `localhost` a `::1` primero |
| `app` | `fsockopen 127.0.0.1:9000` | Solo confirma que el puerto FastCGI acepta conexión; el health real de la cadena lo hace `web` |
| `reverb` | `file_get_contents http://127.0.0.1:8080/up` | **Reverb responde 200 en `/up`** y 404 en `/` y `/health` — verificado con curl contra el contenedor real |
| `pgsql` | `pg_isready -q -d $DB_DATABASE -U $DB_USERNAME` | |
| `redis` | `redis-cli ping` | |
| `mailpit` | `wget -qO- http://localhost:8025/readyz` | **Mailpit expone `/readyz`**, verificado en la versión instalada |
| `queue` / `scheduler` | Sin healthcheck, a propósito | `queue:monitor` comprueba que Redis responde, no que el worker siga vivo — pasaría en verde con el proceso muerto. Se prefiere no declarar un healthcheck ficticio y dejar que `restart: unless-stopped` cubra la caída del proceso |

Señales adicionales de salud real: un broadcast que no pudo entregarse queda en `failed_jobs` (`php artisan queue:failed`); un fallo de autorización de canal se ve como `POST /broadcasting/auth` con 403; los logs de la aplicación van a `storage/logs/laravel.log` dentro del contenedor `app` (canal `stack`/`single`, nivel por `LOG_LEVEL`), y el worker/scheduler/Reverb escriben además a su propio stdout.

## 17. Cómo ejecutar smoke

```bash
scripts/smoke.sh <base-url>
```

Solo lectura: health (`/up`), portada pública, `/negocios`, `/como-funciona`, `/login`, el bundle JS referenciado por la portada, y el gateway de Reverb (`/apps/*` responde "matching application" o "authentication signature", cualquiera de las dos confirma que Reverb está detrás del proxy). Con `SMOKE_EMAIL`/`SMOKE_PASSWORD` en el entorno, agrega login de API y `GET /api/services`.

Verificación manual completa (recomendada tras el primer deployment real y tras cualquier cambio de infraestructura): cola consumiendo jobs, emails llegando a Mailpit, pago simulado + webhook + confirmación de reserva, y Reverb con dos sesiones de navegador — el procedimiento paso a paso está en `CLAUDE.md` ("Smoke de dos navegadores" y "Smoke de fallo de Reverb"), que ya cubre aislamiento entre negocios y el caso de Reverb caído sin afectar la corrección del dominio.

## 18. Qué datos pueden destruirse y cuáles no

La demo es **descartable por decisión de producto**: no se requiere backup histórico de sus reservas, y el reset semanal las destruye a propósito (§12, §15). Lo único que **no puede perderse entre reinicios normales** es el volumen de PostgreSQL (`pgsql-data`, §6) — es el único dato irrecuperable del stack. Redis y Mailpit son convenientes de conservar pero no críticos; perderlos entre reinicios pierde a lo sumo jobs encolados sin ejecutar o correos ya vistos por sus destinatarios.

## 19. Cómo hacer rollback

Ver `docs/RELEASE.md` para el procedimiento completo. Resumen: se elige la imagen o el digest del release anterior (§2) y se vuelve a levantar el stack contra ella. **El rollback de imagen no revierte el esquema** — si el release que se abandona incluyó migraciones nuevas, hace falta restaurar desde backup de base de datos o aplicar una migración correctiva; volver el código atrás por sí solo no deshace un `migrate --force` ya aplicado.

## 20. Qué NO exponer nunca

- PostgreSQL y Redis: solo en la red interna del stack, sin `ports:` publicados (§5).
- El puerto del dev server de Vite (`5173`): no existe en producción — no hay proceso Node en runtime (§1).
- `/docs/api` (OpenAPI de Scramble): dependencia de desarrollo; con `composer install --no-dev` (como se construye la imagen productiva) ni siquiera se registra.
- `APP_DEBUG=true` en un entorno accesible: filtra entorno y stack traces.
- `.env`, `APP_KEY`, `REVERB_APP_SECRET`, credenciales de base de datos/Redis/SMTP y tokens de Sanctum.

**Excepción deliberada: Mailpit sí se expone.** Es la bandeja pública de la demo (§14, `01-reservahub.md` §11.5), sin autenticación ni aislamiento por usuario — cualquiera con la URL ve todos los correos capturados, de cualquier visitante. Es una limitación aceptada del modelo de demo compartida, no un defecto a corregir; el operador decide su hostname público igual que decide el de la aplicación principal.

## 21. Frontera repositorio ↔ operaciones

**ReservaHub entrega:**

```text
Dockerfile productivo
compose productivo portable
imágenes GHCR
Nginx
PHP-FPM
queue
scheduler
Reverb
PostgreSQL
Redis
Mailpit
healthchecks
restart policies
migraciones
DemoSeeder
demo:reset
demo:restore-access
contrato de entorno
CI
workflow de release
smoke checks
README
DEPLOYMENT_HANDOFF
procedimiento de rollback
```

**El agente de operaciones del VPS decide y ejecuta:**

```text
distribución Linux
usuario del servidor
SSH
firewall
Docker del host
estructura física /srv
paths reales de volúmenes
reverse proxy del host
Cloudflare Proxy vs Tunnel
DNS
secretos reales
hostname real
certificados
scheduling real
limpieza real de Mailpit
retención real de Mailpit
reboot recovery
snapshots/backups del host
pull de release
migraciones reales
primer deployment
smoke real
rollback real
```

La Fase 12 no filtra decisiones de host hacia este repositorio sin necesidad.

## 22. Consumo de recursos

Dos mediciones, **no intercambiables** — corresponden a topologías de proceso distintas y ninguna es una garantía de dimensionamiento para un VPS real.

**Medición heredada** (Laravel Sail + `artisan serve` sobre WSL2, dataset chico, un solo queue worker, pocas conexiones Reverb, PostgreSQL sin tuning de producción):

```text
reposo       ≈ 0,30 GB
uso normal   ≈ 0,36 GB
pico medido  ≈ 0,45 GB
```

**No predice producción**, porque producción usa Nginx + PHP-FPM, un pool de workers configurado explícitamente y, en el modelo multiproyecto, comparte el host con otros servicios.

**Medición real del stack productivo** (Tarea 12, `docker stats` sobre los ocho contenedores de `compose.production.yaml`, base sembrada con `DemoSeeder` pero **sin** tráfico real encima — el paso de carga con navegador de esa misma tarea no pudo ejecutarse, así que esto es consumo "recién arrancado y sembrado", no "uso normal" ni "pico"):

| Contenedor | Memoria | CPU |
|---|---|---|
| `app` | 42,5 MiB | 0,00 % |
| `reverb` | 39,2 MiB | 0,00 % |
| `queue` | 38,0 MiB | 0,00 % |
| `scheduler` | 36,7 MiB | 0,07 % |
| `web` | 11,1 MiB | 0,00 % |
| `pgsql` | 50,1 MiB | 0,02 % |
| `redis` | 5,6 MiB | 0,12 % |
| `mailpit` | 11,1 MiB | 0,00 % |
| **Total** | **≈ 234 MiB (≈ 0,23 GB)** | |

Estos ≈234 MiB no son directamente comparables con la medición heredada de arriba (topología de contenedores distinta, y esta cifra no incluye la carga de un uso real). Queda pendiente, para cuando el agente de operaciones pueda ejercer tráfico real contra un deployment, volver a medir "uso normal" y "pico" sobre esta misma topología productiva.

**Variables que dominan la RAM real en producción**, de mayor a menor impacto esperado:

- El pool de PHP-FPM — `pm.max_children` y `pm = static` (o `dynamic`) son la variable principal: cada worker PHP-FPM reserva su propia memoria.
- El tuning de PostgreSQL (`shared_buffers`, `work_mem`, conexiones máximas).
- Cada worker de cola adicional, si se escala horizontalmente (§10 de `01-reservahub.md` nota que escalar es seguro: recordatorios deduplicados, advisory locks).
- Reverb bajo uso real (conexiones WebSocket concurrentes).
- Linux, Docker, builds de imagen, logs y page cache consumen memoria del host **aparte** de los ocho contenedores — no está incluida en ninguna de las dos mediciones de arriba.

**Referencia inicial** — la aplicación no codifica ni depende de ningún tamaño de VPS específico:

```text
ReservaHub solo         → un VPS de 4 GB debería tener margen cómodo.
Servidor multiproyecto  → 8 GB como punto de partida.
```
