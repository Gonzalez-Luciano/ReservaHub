# Release y rollback — ReservaHub

Documento de referencia para cortar una release, saber exactamente qué esquema de base de
datos corresponde a `v1.0.0`, y revertir una imagen de producción sin asumir de más sobre
el estado del esquema. Complementa a [`docs/DEPLOYMENT_HANDOFF.md`](DEPLOYMENT_HANDOFF.md)
(contrato de aplicación para quien opera el servidor) — este documento es sobre *versionado
y reversión*, no sobre cómo administrar el VPS.

## 1. Cómo se corta una release

1. `main` está en verde (CI, workflow `ci.yml`, pasando en el commit que se va a etiquetar).
2. Se crea un tag `vX.Y.Z` sobre ese commit de `main` y se lo empuja:
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```
3. El tag dispara `.github/workflows/release.yml`. El workflow:
   - construye y publica `reservahub-app` (imagen de aplicación: `app`, `queue`, `scheduler`
     y `reverb` corren la misma imagen, solo cambia el comando);
   - construye `reservahub-web` a partir de la imagen de `reservahub-app` recién publicada
     (`build-args: APP_IMAGE=...`), para que ambas contengan literalmente el mismo
     `public/build`;
   - etiqueta cada imagen con tres tags: `X.Y.Z` (versión semántica, sin el prefijo `v`),
     `sha-<commit>` (trazabilidad exacta) y `latest` (conveniencia, nunca una referencia de
     deployment);
   - imprime los digests de ambas imágenes en el resumen del run (`$GITHUB_STEP_SUMMARY`),
     con la advertencia explícita "Fijar el digest en producción. No desplegar por `latest`."

No hay paso manual entre el tag y las imágenes publicadas: si el tag existe en GHCR con las
tres etiquetas esperadas, la release se cortó correctamente.

## 2. Qué se despliega

Una release concreta (`X.Y.Z`) o sus digests — nunca otra cosa. En particular:

- **Nunca** un working tree sin versionar: si no hay un tag `vX.Y.Z` apuntando al commit, no
  es una release, es un build de desarrollo.
- **Nunca** una imagen construida a mano sin referencia a un commit conocido: toda imagen que
  llegue a producción tiene que poder trazarse a un `sha-<commit>` publicado por el workflow.
- **Nunca `latest` como referencia de producción**: `latest` es cómodo para explorar la imagen
  manualmente, pero apuntar producción a una etiqueta móvil significa que un `docker compose
  pull` sin cambiar nada más puede traer un release distinto sin que nadie lo pidiera.

### Releases publicadas

Los digests son la referencia inmutable: una etiqueta (`1.0.0`) puede reapuntarse, un digest no.
Es lo que se fija en producción.

| Versión | Commit | Imagen | Digest |
|---|---|---|---|
| `1.0.0` | `53d79a4` | `reservahub-app` | `sha256:b621765f4c8e15cf8419f4f5eed8b2f1de89b8792f47421f2d13833c7b831d40` |
| `1.0.0` | `53d79a4` | `reservahub-web` | `sha256:c3af88e762d4e61222ae52e278d6a736d0d074424cc1e1c14fab87591f956bca` |

Verificado tras la publicación: ambas imágenes se descargan **sin autenticación** (paquetes
públicos), y sus labels OCI declaran `version=1.0.0` y
`revision=53d79a4d3d39678b27e85374483d2826918f2003`.

Fijar un digest en el compose de producción:

```yaml
APP_IMAGE=ghcr.io/gonzalez-luciano/reservahub-app@sha256:b621765f4c8e15cf8419f4f5eed8b2f1de89b8792f47421f2d13833c7b831d40
WEB_IMAGE=ghcr.io/gonzalez-luciano/reservahub-web@sha256:c3af88e762d4e61222ae52e278d6a736d0d074424cc1e1c14fab87591f956bca
```

## 3. Esquema de `v1.0.0`

**23 migraciones**, verificadas contra `database/migrations/` y `php artisan migrate:status`
en el momento de escribir este documento. La última es
`2026_08_18_000004_add_payment_expires_at_to_bookings_table`. Todo deployment de `v1.0.0`
corresponde exactamente a este esquema:

```text
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_08_06_000001_create_businesses_table
2026_08_06_000002_add_business_id_and_role_to_users_table
2026_08_07_000001_create_services_table
2026_08_07_000002_create_employee_invitations_table
2026_08_07_000003_create_employee_service_table
2026_08_07_000004_create_schedules_table
2026_08_07_000005_create_schedule_breaks_table
2026_08_07_000006_create_time_offs_table
2026_08_07_000007_add_missing_foreign_key_indexes
2026_08_08_000001_create_bookings_table
2026_08_09_000001_create_booking_status_histories_table
2026_08_10_000001_add_index_to_booking_status_histories_changed_by
2026_08_11_000001_create_notifications_table
2026_08_11_000002_create_booking_reminders_table
2026_08_12_210520_create_personal_access_tokens_table
2026_08_14_000001_create_business_holidays_table
2026_08_18_000001_create_payments_table
2026_08_18_000002_create_webhook_events_table
2026_08_18_000003_create_simulated_provider_payments_table
2026_08_18_000004_add_payment_expires_at_to_bookings_table
```

Si una release futura agrega migraciones, su entrada en el §6 (registro de deploys) debe
decirlo explícitamente — sin eso, un rollback futuro no tiene contra qué comparar.

## 4. Rollback por versión de imagen

```text
actual:    1.0.1
rollback:  1.0.0
```

El operador fija la versión (o el digest) anterior y vuelve a levantar el stack. El rollback
**no reconstruye código**: solo cambia qué imagen corre.

```bash
APP_IMAGE=ghcr.io/gonzalez-luciano/reservahub-app:1.0.0 \
WEB_IMAGE=ghcr.io/gonzalez-luciano/reservahub-web:1.0.0 \
docker compose -f compose.production.yaml up -d
```

`APP_IMAGE` y `WEB_IMAGE` son las mismas variables que `compose.production.yaml` ya resuelve
con fallback a `latest` (`${APP_IMAGE:-...:latest}`, `${WEB_IMAGE:-...:latest}`) — fijarlas
explícitamente es lo que convierte un `up -d` genérico en un rollback dirigido a una versión
concreta.

## 5. El rollback de imagen no revierte el esquema

Esta es la advertencia central de este documento. Si la release que se está revirtiendo
aplicó migraciones, volver a la imagen anterior deja **código viejo corriendo contra un
esquema nuevo**. El código viejo no sabe nada de las columnas o tablas que la migración más
reciente agregó, y puede fallar de formas no obvias (columnas `NOT NULL` inesperadas,
lecturas que asumen una forma de fila que ya no es universal, etc.).

Antes de revertir, hay que comprobar si hubo migraciones entre las dos versiones:

```bash
git diff --name-only v1.0.0..v1.0.1 -- database/migrations
```

- Si esa lista está **vacía**, el rollback de imagen es seguro y completo: no hay divergencia
  de esquema que reconciliar.
- Si **no** está vacía, el rollback de imagen por sí solo no alcanza y requiere análisis
  independiente: restaurar desde snapshot de base de datos, o escribir una migración
  correctiva que deje el esquema compatible con el código que se está restaurando.

Las migraciones futuras deberían pensarse compatibles con rollback siempre que sea
razonable: agregar columnas `nullable` antes de empezar a usarlas (en vez de agregarlas ya
`NOT NULL` en el mismo paso en que el código empieza a depender de ellas), y no renombrar
columnas o tablas en un solo paso sin una fase intermedia que tolere ambos nombres.

## 6. Registro de deploys

Cada deploy anota versión, digest, commit y si aplicó migraciones. Formato sugerido:

| Fecha | Versión | Digest (`app` / `web`) | Commit | ¿Aplicó migraciones? |
|---|---|---|---|---|
| — | `1.0.0` | — | — | Sí — esquema inicial, ver §3 |

Esta tabla se completa en el momento del primer deployment real (fuera del alcance de este
repositorio, ver `docs/DEPLOYMENT_HANDOFF.md` §21) y en cada release posterior. Sin este
registro, decidir si un rollback entre dos versiones dadas es seguro (§5) requiere reconstruir
el historial a mano desde los tags de git en vez de leerlo directamente.

## 7. Backups

ReservaHub público es una demo descartable y **no requiere backup histórico** de sus
reservas: el reset semanal las destruye a propósito (ver `docs/DEPLOYMENT_HANDOFF.md` §12,
§15, §18). El VPS puede usar snapshots antes de cambios importantes (por ejemplo, antes de
aplicar una release que incluye migraciones); esa política es de **operaciones**, no de este
repositorio. El repositorio no incluye cron de backup, rutas, credenciales ni buckets — eso
es responsabilidad del agente de operaciones del VPS (ver el límite de responsabilidad en
`CLAUDE.md`).
