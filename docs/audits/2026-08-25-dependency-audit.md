# Auditoría de dependencias previa a v1.0.0

**Fecha:** 2026-08-25
**Alcance:** `composer audit` (PHP) y `pnpm audit` (JS), cada uno en su variante completa y en su
variante de solo-runtime (`--no-dev` / `--prod`), para separar lo que puede bloquear la release de
lo que solo toca herramientas de desarrollo.

Esta es la Tarea 16 del plan de Fase 12 (release readiness). Regla del plan (§12.14): una
vulnerabilidad alta o crítica **que afecte runtime** se resuelve antes de `v1.0.0`. Un advisory que
solo toca herramientas de desarrollo no bloquea automáticamente la release, pero se documenta por
qué se acepta.

## Por qué `--no-dev` / `--prod` responden la pregunta que importa

- **Backend:** la imagen de producción (`docker/production/app.Dockerfile`, líneas 5 y 19-20) corre
  `composer install --no-dev`. Todo lo que está en `require-dev` de `composer.json` (Scramble,
  Pail, Pao, Pint, Sail, Mockery, Collision, PHPUnit) **nunca llega al contenedor que corre en
  producción** — nótese que `fakerphp/faker` está en `require`, no en `require-dev`, así que sí
  llega a producción (se usa en seeders de demo, fuera del alcance de esta auditoría de
  seguridad). `composer audit --no-dev` audita exactamente el subconjunto de `require` instalado
  en producción.
- **Frontend:** `pnpm build` (Vite) compila a estáticos. `package.json` tiene en `dependencies` solo
  `@inertiajs/react`, `react` y `react-dom`; todo lo demás (`vite`, `@tailwindcss/vite`,
  `@vitejs/plugin-react`, `laravel-vite-plugin`, `laravel-echo`, `@laravel/echo-react`, `pusher-js`,
  `tailwindcss`, `concurrently`) está en `devDependencies`. `pnpm audit --prod` audita solo el árbol
  de `dependencies`, que es la aproximación más cercana a "qué puede terminar en el bundle servido al
  navegador" sin inspeccionar el bundle final paquete por paquete.

Verificado directamente en el repo:

```
$ grep -n "composer install\|no-dev" docker/production/app.Dockerfile
5:# --no-dev deja fuera Scramble (la doc OpenAPI), Sail, Pint, PHPUnit y
19:RUN composer install \
20:        --no-dev \
121:    composer dump-autoload --optimize --no-dev --no-interaction \
```

```json
// package.json
"devDependencies": {
    "@laravel/echo-react": "^2.4.0",
    "@tailwindcss/vite": "^4.0.0",
    "@vitejs/plugin-react": "^6.0.5",
    "concurrently": "^9.0.1",
    "laravel-echo": "^2.4.0",
    "laravel-vite-plugin": "^3.1",
    "pusher-js": "^8.6.0",
    "tailwindcss": "^4.0.0",
    "vite": "^8.0.0"
},
"dependencies": {
    "@inertiajs/react": "^3.6.1",
    "react": "^19.2.8",
    "react-dom": "^19.2.8"
}
```

## Paso 1 — Correr las cuatro auditorías

Entorno: `docker compose exec -T laravel.test ...`, worktree `feat/phase-12-release-readiness`.
Composer 2.10.2, PHP 8.5.9.

### `composer audit --format=plain` (completo, incluye `require-dev`)

```
$ docker compose exec -T laravel.test composer audit --format=plain
No security vulnerability advisories found.
```

### `composer audit --no-dev --format=plain` (solo lo que corre en producción)

```
$ docker compose exec -T laravel.test composer audit --no-dev --format=plain
No security vulnerability advisories found.
```

### `pnpm audit` (completo, incluye `devDependencies`)

```
$ docker compose exec -T laravel.test bash -lc "pnpm audit"
┌─────────────────────┬────────────────────────────────────────────────────────┐
│ high                │ nanoid: custom generators can loop indefinitely when   │
│                     │ size is zero                                           │
├─────────────────────┼────────────────────────────────────────────────────────┤
│ Package             │ nanoid                                                 │
├─────────────────────┼────────────────────────────────────────────────────────┤
│ Vulnerable versions │ <3.3.18                                                │
├─────────────────────┼────────────────────────────────────────────────────────┤
│ Patched versions    │ >=3.3.18                                               │
├─────────────────────┼────────────────────────────────────────────────────────┤
│ Paths               │ .>@tailwindcss/vite>vite>postcss>nanoid                │
│                     │                                                        │
│                     │ .>@vitejs/plugin-react>vite>postcss>nanoid             │
│                     │                                                        │
│                     │ .>laravel-vite-plugin>vite>postcss>nanoid              │
│                     │                                                        │
│                     │ ... Found 4 paths, run `pnpm why nanoid` for more      │
│                     │ information                                            │
├─────────────────────┼────────────────────────────────────────────────────────┤
│ More info           │ https://github.com/advisories/GHSA-2v37-7h3g-55p8      │
└─────────────────────┴────────────────────────────────────────────────────────┘
1 vulnerabilities found
Severity: 1 high
```

Detalle de la cadena de dependencia (`pnpm why nanoid`):

```
$ docker compose exec -T laravel.test bash -lc "pnpm why nanoid"
nanoid@3.3.17
└─┬ postcss@8.5.25
  └─┬ vite@8.2.0
    ├─┬ @tailwindcss/vite@4.3.3
    │ └── the root project (devDependencies)
    ├─┬ @vitejs/plugin-react@6.0.5
    │ └── the root project (devDependencies)
    ├─┬ laravel-vite-plugin@3.1.3
    │ └── the root project (devDependencies)
    └── the root project (devDependencies)

Found 1 version of nanoid
```

### `pnpm audit --prod` (solo `dependencies`, lo que puede llegar al bundle)

```
$ docker compose exec -T laravel.test bash -lc "pnpm audit --prod"
No known vulnerabilities found
```

## Paso 2 — Clasificación

| # | Paquete | Severidad | Advisory | ¿Aparece en `--no-dev`/`--prod`? | Decisión |
|---|---------|-----------|----------|-----------------------------------|----------|
| 1 | `nanoid` (transitivo: `postcss` ← `vite` ← `@tailwindcss/vite`, `@vitejs/plugin-react`, `laravel-vite-plugin`, todos en `devDependencies`) | Alta | [GHSA-2v37-7h3g-55p8](https://github.com/advisories/GHSA-2v37-7h3g-55p8) — un generador custom con `size: 0` puede entrar en loop infinito | No aparece en `pnpm audit --prod`. `nanoid` no está en `dependencies` ni es alcanzable desde `@inertiajs/react`, `react` o `react-dom`. | **Se acepta. No bloquea v1.0.0.** |

No hay ningún otro advisory: ambas corridas de `composer audit` (completa y `--no-dev`) están
limpias, y `pnpm audit --prod` está limpio.

### Por qué el hallazgo #1 no afecta runtime

- `nanoid` solo entra al árbol de dependencias a través de `postcss`, que a su vez solo lo usa
  `vite` — la herramienta de build. Las tres rutas que lo arrastran
  (`@tailwindcss/vite`, `@vitejs/plugin-react`, `laravel-vite-plugin`) están las tres en
  `devDependencies` de `package.json`, nunca en `dependencies`.
- `vite build` (lo que corre `pnpm build`) ejecuta `nanoid` **en el proceso de Node del build**, no
  en el navegador. El código de `nanoid` no se emite dentro del bundle JS servido a los clientes —
  el bundle final contiene el código de `@inertiajs/react`, `react`, `react-dom` y el código propio
  de la app, nada de la toolchain de build.
- El propio advisory describe un uso indebido (un *custom alphabet/size generator* invocado con
  `size: 0` produciendo un loop infinito) que depende del *caller* pasando ese argumento — este
  repo no usa `nanoid` directamente en ningún punto (ni en `resources/js`, ni en configuración de
  Vite propia); solo llega como dependencia transitiva de la cadena de PostCSS de Vite, que lo
  invoca con sus propios parámetros internos, no con input controlado por un atacante externo.
- `pnpm audit --prod` — la corrida que solo mira `dependencies` — no lo reporta, confirmando que no
  es alcanzable desde el árbol que instala el runtime.

**Nota sobre la aproximación `dependencies`/`devDependencies` en el frontend:** `laravel-echo`,
`@laravel/echo-react` y `pusher-js` sí se ejecutan en el navegador (el cliente de Reverb, Fase 10)
pese a estar en `devDependencies` — la separación `dependencies`/`devDependencies` en un proyecto
Vite no determina qué entra al bundle (eso lo decide qué se importa desde el código fuente), solo
qué se instala con `pnpm install --prod`. Esto no cambia la conclusión de esta auditoría porque
ninguno de esos tres paquetes apareció en ningún advisory — se señala por rigor, no porque afecte la
clasificación de un hallazgo real.

## Paso 3 — Actualización

No aplica. El único advisory encontrado (nanoid, Alta) **no** aparece en la corrida de solo-runtime
(`pnpm audit --prod`), así que no cumple la condición "alta/crítica **y** afecta runtime" que exige
resolución antes de `v1.0.0`. No se tocó ninguna dependencia; `composer.lock` y `pnpm-lock.yaml`
quedan sin cambios frente a `HEAD`.

## Conclusión

**SIN ADVISORIES QUE AFECTEN RUNTIME.**

- Backend: 0 advisories en `composer audit` completo y en `composer audit --no-dev`.
- Frontend: 1 advisory (`nanoid`, Alta) en `pnpm audit` completo, 0 en `pnpm audit --prod`. Es
  transitivo de la toolchain de build de Vite (`devDependencies`), no llega al bundle ni al
  navegador — se acepta sin acción, documentado arriba.
- No se actualizó ninguna dependencia; no aplica volver a correr la suite de tests ni Pint por este
  motivo.
