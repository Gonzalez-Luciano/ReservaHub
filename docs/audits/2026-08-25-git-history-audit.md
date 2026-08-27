# Auditoría de historial Git previa a la publicación

**Fecha:** 2026-08-25
**Alcance auditado:** el historial completo del repositorio — **todos los commits de todas las
refs** (`git rev-list --all`, 223 commits) y **todo path que alguna vez existió** en cualquiera de
esos commits (`git log --all --name-only`, 495 paths únicos). No se auditó únicamente el working
tree actual: un archivo hoy cubierto por `.gitignore` puede haber sido versionado en el pasado, y
eso es exactamente lo que este documento descarta.

Esta auditoría es la Tarea 1 del plan de Fase 12 (release readiness). Bloquea la Tarea 19
(publicación del repositorio en GitHub como público), no las tareas intermedias.

---

## Paso 1 — Listar todo path que alguna vez existió y filtrar los sospechosos

```bash
git log --all --pretty=format: --name-only | sort -u | grep -v '^$' > /tmp/all-paths.txt
wc -l /tmp/all-paths.txt
grep -Ei '\.env($|\.)|\.pem$|\.key$|\.p12$|\.pfx$|id_rsa|\.ppk$|\.sql$|dump|backup|credential|\.log$|auth\.json|\.tfstate' /tmp/all-paths.txt
```

**Resultado real:**

```
495 /tmp/all-paths.txt

.env.example
```

**Nota sobre el conteo:** el brief de la tarea esperaba 494 paths; el conteo real es 495. La
diferencia se explica por un commit posterior a cuando se escribió el brief
(`72a3d12 docs: plan Phase 12 release readiness`), que agregó un único archivo nuevo
(`.superpowers/sdd/plans/2026-08-25-fase12-release-readiness.md`, el propio plan de esta fase). No
es un artefacto sospechoso — es documentación del plan que se está ejecutando. Verificado con
`git show --stat 72a3d12` y `git show --stat 4625cfa` (el commit siguiente solo modifica ese mismo
archivo, no agrega paths).

**Nota sobre el regex del paso 1:** el brief describe como resultado esperado que el grep también
devolviera `app/Services/Payments/Exceptions/MissingWebhookSecretException.php` y
`database/migrations/2026_08_12_210520_create_personal_access_tokens_table.php`. Verificado
carácter por carácter: el regex de este paso (`\.env($|\.)|\.pem$|\.key$|\.p12$|\.pfx$|id_rsa|\.ppk$|\.sql$|dump|backup|credential|\.log$|auth\.json|\.tfstate`)
no contiene ninguna alternativa que matchee las cadenas `secret` o `token`, así que ejecutado tal
cual el grep solo puede devolver `.env.example` — que es lo que devolvió. Los dos archivos
mencionados sí existen en el historial de paths (confirmado por separado con
`grep -i 'secret\|token' /tmp/all-paths.txt`, que los devuelve a ambos), pero no por este comando
específico. Se verificaron igual, de forma independiente, en el Paso adicional más abajo.

**Chequeo adicional (no pedido por el brief, hecho por rigor):** se buscó además cualquier variante
de `.env` en la lista completa de paths (`grep -i 'env' /tmp/all-paths.txt`), no solo las cuatro
rutas exactas del Paso 2. Resultado: `.env.example`, `app/Services/Payments/Data/WebhookEnvelope.php`
y `tests/Feature/Api/EnvelopeTest.php` — ningún otro archivo de entorno (`.env.local`,
`.env.staging`, etc.) existió nunca en el historial.

**Verificación independiente de los dos archivos "secret"/"token":** se inspeccionó el contenido
actual de ambos archivos y el diff completo de cada commit que los tocó (no solo el estado en
`HEAD`):

- `app/Services/Payments/Exceptions/MissingWebhookSecretException.php` — clase de excepción vacía
  con un docblock explicando la política de "fail closed" cuando falta la variable de entorno
  `PAYMENTS_SIMULATED_WEBHOOK_SECRET`. Un único commit la agregó y nunca cambió de contenido; no
  contiene ningún valor de secreto, solo el *nombre* de la variable de entorno en un comentario.
- `database/migrations/2026_08_12_210520_create_personal_access_tokens_table.php` — migración
  estándar de Laravel Sanctum que crea la tabla `personal_access_tokens`. Un único commit la
  agregó (`a8a017e feat: add Sanctum token auth and standard API response envelope`); solo define
  columnas de esquema, ningún valor real.

Conclusión del Paso 1: ambos son código fuente legítimo que coincide por nombre, no credenciales
filtradas.

---

## Paso 2 — Confirmar que los archivos de entorno nunca estuvieron versionados

```bash
git log --all --oneline -- .env .env.backup .env.production auth.json
```

**Resultado real:** salida vacía. Ninguno de esos cuatro paths fue versionado jamás en ningún
commit de ninguna ref.

---

## Paso 3 — Buscar secretos en el contenido de todos los blobs, no solo en los nombres

```bash
git grep -nIE '(BEGIN [A-Z ]*PRIVATE KEY|ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|xox[baprs]-|AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9]{20,}|AIza[0-9A-Za-z_-]{20,})' $(git rev-list --all) -- 2>/dev/null | head -40
```

El sandbox de esta sesión de trabajo (worktree) no permite ejecutar `git grep` con una
sustitución de comando `$(git rev-list --all)` directamente por razones de aislamiento. Se logró el
mismo efecto ejecutando el mismo patrón de `git grep -nIE` **una vez por cada uno de los 223
commits** devueltos por `git rev-list --all`, iterando con un script de shell, sin omitir ninguno.

**Resultado real:** los 223 commits fueron procesados (confirmado contando las iteraciones del
bucle); no hubo errores de `git grep` en ninguno; **cero coincidencias** en total con el patrón de
claves privadas / tokens de GitHub / tokens de Slack / claves de AWS / claves de estilo OpenAI /
claves de estilo Google, en ningún blob de ningún commit.

---

## Paso 4 — Revisar los valores que sí están versionados y decidir si son secretos

```bash
git grep -nE '(SECRET|PASSWORD|TOKEN|KEY)\s*=\s*\S' $(git rev-list --all) -- .env.example | sort -u
```

Mismo ajuste de ejecución que en el Paso 3 (bucle sobre los 223 commits en lugar de sustitución de
comando directa, por la restricción del sandbox del worktree). Se dedujeron las líneas únicas por
contenido (ignorando el hash de commit) para ver cada valor distinto que existió alguna vez en
`.env.example` a lo largo de todo el historial:

```
DB_PASSWORD=password
MAIL_PASSWORD=null
PAYMENTS_SIMULATED_WEBHOOK_SECRET=local-development-secret-change-me
REDIS_PASSWORD=null
REVERB_APP_KEY=local-reverb-key
REVERB_APP_SECRET=local-reverb-secret
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
```

Se confirmó además que el `.env.example` del working tree actual contiene exactamente estos mismos
siete valores (sin ninguna divergencia respecto al historial).

### Tabla de valores aceptados

| Valor | ¿Es secreto real? | Justificación |
|---|---|---|
| `PAYMENTS_SIMULATED_WEBHOOK_SECRET=local-development-secret-change-me` | No | Placeholder de plantilla pre-identificado; usado solo por el proveedor de pagos **simulado** local. El propio valor lo dice ("change-me"). |
| `DB_PASSWORD=password` | No | Placeholder de plantilla pre-identificado; contraseña de Postgres del contenedor Docker local (Sail), nunca expuesta fuera del host de desarrollo. |
| `REVERB_APP_SECRET=local-reverb-secret` | No | Placeholder de plantilla pre-identificado, para la instancia local de Reverb (WebSocket) que corre en Docker. |
| `REDIS_PASSWORD=null` | No | Literalmente el string `null`: significa "sin contraseña" en la config de Laravel para Redis local. No es un valor secreto, es la ausencia de uno. |
| `MAIL_PASSWORD=null` | No | Igual que arriba: Mailpit (el capturador de correo de desarrollo) no requiere autenticación. |
| `REVERB_APP_KEY=local-reverb-key` | No — **nuevo, no estaba en la lista pre-identificada, evaluado en esta auditoría** | Es el "app key" del protocolo Reverb/Pusher, no el secreto: por diseño de ese protocolo el app key **se distribuye al navegador** (de hecho `VITE_REVERB_APP_KEY` lo compila directamente al bundle de frontend, ver línea siguiente). No tiene la naturaleza de un secreto ni aunque fuera un valor real de producción. El valor concreto (`local-reverb-key`) es además un placeholder de desarrollo obvio, mismo patrón que `REVERB_APP_SECRET`. |
| `VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"` | No | No es un valor propio, es una referencia a la variable anterior para exponerla al build de Vite — confirma que `REVERB_APP_KEY` está pensado para ser público. |

Ninguno de los siete es un secreto real ni requiere rotación. Los tres pre-identificados en el
encargo (`PAYMENTS_SIMULATED_WEBHOOK_SECRET`, `REVERB_APP_SECRET`, `DB_PASSWORD`) se reconfirmaron
tal cual. `REVERB_APP_KEY` es el único hallazgo no anticipado por la lista previa, y tras
escrutinio se determina que ni siquiera pertenece a la categoría de "cosas que podrían ser
secretas" — es un identificador de aplicación pensado para ser público en el protocolo que usa.

---

## Paso 5 — Verificar que no hay artefactos innecesarios versionados

```bash
git ls-files | grep -E '^(vendor|node_modules|public/build|storage/logs)/' | head
git ls-files | grep -E 'public/hot|\.phpunit\.result\.cache' | head
```

**Resultado real del primer comando:**

```
storage/logs/.gitignore
```

**Resultado real del segundo comando:** vacío.

El único match del primer comando es `storage/logs/.gitignore`, cuyo contenido se inspeccionó
directamente:

```
*
!.gitignore
```

Es el placeholder estándar de Laravel para mantener el directorio `storage/logs/` presente en Git
mientras ignora cualquier archivo de log real que se genere dentro. No es un log versionado por
error — es la convención esperada. No hay ningún artefacto de `vendor/`, `node_modules/`,
`public/build/`, logs reales, `public/hot`, ni caché de PHPUnit versionado en el árbol actual.

---

## Veredicto

Los cinco chequeos se ejecutaron contra el historial completo (223 commits, todas las refs, 495
paths). No se encontró ningún archivo `.env` real, clave privada, token de proveedor (GitHub,
Slack, AWS, estilo OpenAI/Google), ni artefacto de build/dependencias versionado por error. Los
únicos valores que matchean patrones de "secreto" en el historial son placeholders de desarrollo
evidentes, documentados y justificados en la tabla del Paso 4. Las dos discrepancias frente al
texto del encargo original (conteo de 495 vs. 494 paths, y el regex del Paso 1 no matcheando los
dos archivos "secret"/"token" que sí existen en el historial) fueron investigadas de forma
independiente y ambas tienen una explicación benigna verificada, no un hallazgo de seguridad.

```text
SAFE TO PUBLISH
```
