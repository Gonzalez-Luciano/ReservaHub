# Fase 2 — Empresas y tenancy

## Objetivo

Introducir el concepto de `business` (empresa) y multi-tenancy simple: cada usuario pertenece (opcionalmente) a una empresa, las rutas de negocio quedan protegidas por empresa actual, y queda sentado el patrón de scoping que usarán todas las tablas tenant-owned desde Fase 3 en adelante.

## Alcance

- Tabla `businesses` (name, slug, timezone, currency, cancellation_hours, logo_path, is_active).
- `users` gana `business_id` (nullable FK), `role` (enum owner/admin/employee/customer), `is_active`.
- Registro con dos caminos: crear empresa (usuario queda `owner`) o cuenta de cliente (`customer`, `business_id` null, cross-business).
- Middleware `EnsureBusinessContext` en rutas `dashboard/*`: exige `business_id` + rol de negocio (owner/admin/employee), resuelve y bindea la `Business` actual en el container.
- Trait `BelongsToBusiness` + `BusinessScope` (global scope): patrón reutilizable para tablas tenant-owned futuras (services, schedules, bookings...). No se aplica a `users` (customers tienen `business_id` null, no encajan en "siempre filtrar por empresa actual") ni a `businesses` (es la raíz del tenant, no dato tenant-owned).
- `BusinessPolicy` (view/update solo owner/admin de esa empresa) y `UserPolicy` (update/delete solo mismo `business_id`, bloquea cruce entre empresas).
- Tests de registro, middleware y aislamiento cruzado (caso literal del spec: "employee no modifica otra empresa").

## Fuera de alcance (explícitamente, para no mezclar fases)

- Invitación de empleados / admins → Fase 3, junto con CRUD de empleados.
- Servicios, horarios, pausas, licencias → Fase 3.
- Cualquier tabla tenant-owned real usando `BelongsToBusiness` (services, bookings...) → Fase 3+. En Fase 2 el trait queda construido pero sin consumidor todavía.
- UI de administración de usuarios/empresa (listados, edición desde dashboard) → Fase 3+, salvo lo mínimo para que el registro funcione.

## Decisiones

| Decisión | Elegido | Alternativa descartada | Por qué |
|---|---|---|---|
| Registro | Dos caminos (business vs customer) en un mismo form con toggle | Un solo flujo, siempre owner+business | Clientes existen desde ya como cuenta cross-business; evita rehacer registro en Fase 5 |
| `role` storage | Columna `role` string cast a enum PHP (`App\Enums\Role`) | Tablas de roles/permisos separadas | Spec sugiere empezar simple y migrar a permisos granulares después |
| Scoping tenant | Global scope (`BelongsToBusiness` trait) | Scoping explícito por query | Menos riesgo de fuga de datos al olvidar el filtro en fases futuras; ya se sienta la convención ahora aunque el primer consumidor real llega en Fase 3 |
| `users` bajo global scope | No | Sí | `business_id` nullable para customers rompe la semántica de "siempre empresa actual"; se controla por policy en su lugar |
| Middleware | Guard + bind resolver (`Business` en el container) | Guard simple sin bind | Evita reconsultar la empresa actual en cada controller/policy de Fase 3+ |

## Modelo de datos

```
businesses
  id, name, slug (unique), timezone, currency,
  cancellation_hours, logo_path (nullable), is_active, timestamps

users (alter)
  + business_id (nullable FK -> businesses, nullOnDelete, indexed)
  + role (string, enum owner|admin|employee|customer)
  + is_active (bool, default true)
```

## Resultado esperado (criterio de "listo")

1. Migraciones corren limpio; `businesses` existe, `users` tiene las columnas nuevas.
2. Registro con `account_type=business` crea `Business` + `User(role=owner, business_id=business.id)` en una transacción; si falla la creación de cualquiera de los dos, no queda ninguno.
3. Registro con `account_type=customer` crea `User(role=customer, business_id=null)`.
4. Ruta bajo `dashboard/*` devuelve 403 para usuario sin `business_id` (customer o cuenta inactiva de negocio); pasa para owner/admin/employee y la `Business` bindeada en el container coincide con la del usuario.
5. `BusinessPolicy`/`UserPolicy` bloquean acceso cruzado: owner de empresa A no puede ver/editar empresa B ni usuarios de empresa B (403).
6. `vendor/bin/pint --test` y `php artisan test` pasan.

## Testing

TDD por pieza:
- Feature tests de registro (ambos caminos, incluyendo rollback transaccional si falla).
- Feature test de middleware (403 sin negocio, bind correcto con negocio).
- Feature tests de policy (caso "employee no modifica otra empresa" explícito del spec).
- Unit test del enum `Role` si tiene lógica no trivial (helpers `isOwner()` etc.), si no, cubre vía los feature tests de arriba.
