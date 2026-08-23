# Fase 11 — Rediseño frontend y experiencia de demo · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir las 24 páginas Inertia existentes en una demo SaaS profesional y coherente sobre el sistema visual Turno, exponiendo capacidades de backend ya implementadas sin romper ningún invariante de las Fases 9 y 10.

**Architecture:** Tailwind 4 con tokens semánticos en `resources/css/app.css`, primitivas propias en `resources/js/Components/ui/`, componentes de dominio en `resources/js/Components/domain/`, y dos shells (`DashboardLayout`, `PublicLayout`). Los puentes de backend son proyecciones nuevas en controladores existentes más dos controladores nuevos (`HomeController`, `ComoFuncionaController`), siempre a través de las Actions y Policies ya existentes. Cero dependencias de runtime nuevas.

**Tech Stack:** Laravel 13 · Inertia 3 · React 19 · Tailwind CSS 4 · Vite 8 · pnpm · PostgreSQL 18 · Redis · Reverb · PHPUnit

**Spec:** `docs/superpowers/specs/2026-08-23-fase11-redesign-frontend-design.md`

## Global Constraints

- **Entorno:** todo corre en Docker desde este worktree. La app está en `http://localhost:8180`, Mailpit en `http://localhost:8026`, Reverb en el puerto `8081`.
- **Comandos canónicos:**
  - Tests: `docker compose exec -T laravel.test php artisan test`
  - Test dirigido: `docker compose exec -T laravel.test php artisan test --filter=NombreDelTest`
  - Formato: `docker compose exec -T laravel.test vendor/bin/pint --test`
  - Build: `docker compose exec -T laravel.test bash -lc "pnpm build"`
- **Paquetes:** pnpm, nunca npm. **Cero dependencias nuevas de runtime.** Prohibido instalar Playwright, Cypress, Vitest, Jest, shadcn, Radix, Headless UI, o cualquier librería de fechas o de calendario.
- **Paleta canónica:** `--bg #ECEDE8` · `--surface #FFFFFF` · `--border #D9DAD3` · `--muted #676860` · `--fg #191A17` · `--chrome #E3E5DE` · `--chrome-active #D9DAD3`. La marca no tiene hue; el color solo significa estado.
- **Estados:** pendiente `#92400E`/`#FEF3C7` · confirmada `#166534`/`#DCFCE7` · completada `#334155`/`#E2E8F0` · cancelada `#57534E`/`#F5F5F4` · ausencia `#991B1B`/`#FEE2E2`. Nunca solo color: color + icono + etiqueta, y hora tachada en cancelada.
- **Tipografía:** solo `Instrument Sans`, ya auto-hospedada. `tabular-nums` obligatorio en horas, duraciones, importes y contadores. Micro-etiqueta en versalitas (+0.08em) **solo** para metadatos operativos.
- **Superficies:** separación por borde de 1px, jamás por sombra. Exactamente dos sombras, ambas de overlay. Radios 4px (controles) y 6px (superficies), 0 en el spine.
- **Spine:** solo en filas de lista y bloques de agenda. Nunca como borde de acento de tarjeta suelta. Color semántico solo si la entidad tiene estado aprobado.
- **Ventana horaria canónica:** 09:00–18:00 en riel del panel, tira del Home y horarios sembrados.
- **Voz:** español rioplatense, voseo, **primera persona del singular, nunca del plural**. Nada de "pedimos", "nuestro", "hacemos".
- **Nombres de componente Inertia congelados.** Ninguno cambia de nombre: 29 aserciones de test los referencian.
- **Invariantes Fase 9:** el frontend no muta `Payment` ni `Booking`, no saltea rutas firmadas, no toca el proveedor simulado.
- **Invariantes Fase 10:** `BookingChanged` sigue siendo el único evento de broadcast; `BookingsRealtime` conserva su contrato; sin tiempo real para clientes; sin store de tiempo real.
- **Sin datos falsos:** toda cifra visible sale de una consulta real. Prohibido hardcodear datos de aplicación en React.

---

## Estructura de archivos

**Se crean:**

| Archivo | Responsabilidad |
|---|---|
| `resources/js/Components/ui/Button.jsx` | Botón con variantes `primary`/`secondary`/`danger` |
| `resources/js/Components/ui/IconButton.jsx` | Botón de solo icono, exige `aria-label` |
| `resources/js/Components/ui/Field.jsx` | `Input`, `Select`, `Textarea`, `FormField` |
| `resources/js/Components/ui/Surface.jsx` | Superficie blanca con borde |
| `resources/js/Components/ui/PageHeader.jsx` | Título, subtítulo y acciones de página |
| `resources/js/Components/ui/StatusBadge.jsx` | Badge genérico color+icono+texto |
| `resources/js/Components/ui/EmptyState.jsx` | Icono, título, explicación, acción |
| `resources/js/Components/ui/Alert.jsx` | Aviso tintado, sin spine |
| `resources/js/Components/ui/Toast.jsx` | Aviso transitorio `role="status"` |
| `resources/js/Components/ui/TableShell.jsx` | Contenedor de lista con filete entre filas |
| `resources/js/Components/ui/StatCard.jsx` | Cifra con micro-etiqueta |
| `resources/js/Components/ui/Modal.jsx` | `<dialog>` nativo con contrato de accesibilidad |
| `resources/js/Components/ui/ConfirmDialog.jsx` | Modal de confirmación, foco en la acción segura |
| `resources/js/Components/ui/Drawer.jsx` | Panel lateral móvil sobre `<dialog>` |
| `resources/js/Components/ui/icons.jsx` | Set SVG único, grilla 16, stroke 1.5–1.6 |
| `resources/js/Components/domain/BookingStatusBadge.jsx` | Traduce `BookingStatus` a badge |
| `resources/js/Components/domain/PaymentStatusBadge.jsx` | Traduce `PaymentStatus` a badge, prefijo "Seña" |
| `resources/js/Components/domain/BookingActions.jsx` | Acciones de ciclo de vida según estado y permisos |
| `resources/js/Components/domain/SlotPicker.jsx` | Grilla de chips de horario |
| `resources/js/Components/domain/ServiceCard.jsx` | Tarjeta de servicio público con seña |
| `resources/js/Components/domain/ScheduleEditor.jsx` | Vista semanal de horarios, pausas y licencias |
| `resources/js/Components/domain/DayRail.jsx` | Riel del día con carriles deterministas |
| `resources/js/Components/domain/DemoResetCountdown.jsx` | Contador al reinicio diario |
| `resources/js/Components/DemoStrip.jsx` | Franja informativa de demo del Home |
| `resources/js/Pages/ComoFunciona.jsx` | Guía de la demo compartida |
| `app/Http/Controllers/HomeController.php` | Proyección de ocupación del Home |
| `app/Http/Controllers/ComoFuncionaController.php` | Renderiza la guía |
| `tests/Feature/HomeTest.php` | Cobertura del puente del Home |
| `tests/Feature/ComoFuncionaTest.php` | Cobertura de la guía |
| `tests/Feature/Dashboard/DashboardMetricsTest.php` | Cobertura de métricas |
| `tests/Feature/Seeders/DemoSeederTest.php` | Cobertura del dataset |

**Se modifican:** `resources/css/app.css`, `resources/js/Components/{DashboardLayout,PublicLayout,AuthCard,InputError}.jsx`, las 24 páginas de `resources/js/Pages/`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/Dashboard/BookingController.php`, `app/Http/Controllers/Public/{BusinessController,BookingController,MyBookingsController}.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`, `routes/public.php`, `database/seeders/DemoSeeder.php`, `tests/Feature/Public/MyBookingsTest.php`, `tests/Feature/Api/BookingsWriteTest.php`, `.env.example`, `docs/DEPLOYMENT_HANDOFF.md`, `01-reservahub.md`.

**No se toca:** `app/Events/`, `app/Listeners/`, `app/Actions/`, `app/Policies/`, `app/Services/`, `routes/channels.php`, `routes/demo.php`, `resources/js/Components/BookingsRealtime.jsx`, `resources/js/app.jsx`.

---

## Task 1: Reparar los cinco tests dependientes del calendario

Va primero porque "suite en verde" es la puerta de cada tarea siguiente, y hoy la suite falla los domingos por el calendario, no por el código.

**Files:**
- Modify: `tests/Feature/Public/MyBookingsTest.php`
- Modify: `tests/Feature/Api/BookingsWriteTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: suite determinista. Todas las tareas siguientes dependen de esto.

- [ ] **Step 1: Reproducir el fallo con el reloj puesto en domingo**

```bash
docker compose exec -T laravel.test php artisan test --filter=MyBookingsTest
```

Si hoy no es domingo la suite pasa. Para reproducir de forma determinista, verificar primero la causa leyendo `app/Policies/BookingPolicy.php:52-58`: el corte es `starts_at − cancellation_hours` y `parse('next monday')` cae a menos de 24 h cuando la suite corre un domingo desde las 09:00 UTC.

- [ ] **Step 2: Congelar el tiempo en `MyBookingsTest`**

La clase no tiene `setUp()`. Agregarlo justo después de `use RefreshDatabase;`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        // Miércoles fijo: `next monday` queda a cinco días, muy por encima de
        // cualquier `cancellation_hours` de los casos. Sin esto la clase falla
        // los domingos desde las 09:00 UTC, cuando el corte de cancelación de
        // la reserva del lunes siguiente ya pasó.
        $this->travelTo(CarbonImmutable::parse('2026-01-07 08:00', 'UTC'));
    }
```

- [ ] **Step 3: Congelar el tiempo en `BookingsWriteTest`**

La clase ya tiene `setUp()`. Insertar el viaje en el tiempo como **primera** instrucción después de `parent::setUp();`, antes de que se cree cualquier modelo y antes de la línea que asigna `$this->monday`:

```php
        parent::setUp();

        // Ver la nota de MyBookingsTest: `$this->monday` se deriva de
        // `next monday`, así que el reloj tiene que estar fijo antes.
        $this->travelTo(CarbonImmutable::parse('2026-01-07 08:00', 'UTC'));
```

- [ ] **Step 4: Correr las dos clases**

```bash
docker compose exec -T laravel.test php artisan test --filter=MyBookingsTest
docker compose exec -T laravel.test php artisan test --filter=BookingsWriteTest
```

Esperado: PASS en ambas, sin fallos.

- [ ] **Step 5: Correr la suite completa y confirmar 542 verdes**

```bash
docker compose exec -T laravel.test php artisan test
```

Esperado: `Tests: 542 passed`. Antes eran 537 passed y 5 failed.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Public/MyBookingsTest.php tests/Feature/Api/BookingsWriteTest.php
git commit -m "test: freeze the clock in the booking cancellation tests"
```

---

## Task 2: Fundación de tokens en Tailwind 4

**Files:**
- Modify: `resources/css/app.css`

**Interfaces:**
- Produces: variables CSS y utilidades que consumen **todas** las tareas siguientes. Nombres exactos: `--color-bg`, `--color-surface`, `--color-border`, `--color-muted`, `--color-fg`, `--color-chrome`, `--color-chrome-active`, `--color-fg-body`, `--color-fg-placeholder`, `--color-fg-on-ink-muted`, `--color-rule-faint`, `--color-surface-inset`, `--color-surface-disabled`, `--color-slot-taken`, `--color-track-empty-border`, y los pares de estado `--color-{pending,confirmed,completed,cancelled,noshow}-{fg,bg}`.

- [ ] **Step 1: Escribir el bloque de tema**

Reemplazar el `@theme` actual de `resources/css/app.css` por:

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';

    /* Marca sin hue: el color queda reservado para significado. */
    --color-bg: #ECEDE8;
    --color-surface: #FFFFFF;
    --color-border: #D9DAD3;
    --color-muted: #676860;
    --color-fg: #191A17;
    --color-chrome: #E3E5DE;
    --color-chrome-active: #D9DAD3;

    /* Escala de apoyo. */
    --color-fg-body: #3A3835;
    --color-fg-placeholder: #A8A29E;
    --color-fg-on-ink-muted: #B8B5AE;
    --color-rule-faint: #EDEEE9;
    --color-surface-inset: #FAFAF8;
    --color-surface-disabled: #FAFAF9;
    --color-slot-taken: #E1E3DB;
    --color-track-empty-border: #C3C5BB;

    /* Estado. Nunca se usan como color de marca. */
    --color-pending-fg: #92400E;
    --color-pending-bg: #FEF3C7;
    --color-pending-border: #EBD8A8;
    --color-pending-strong: #78350F;
    --color-pending-block: #FFFBEB;
    --color-confirmed-fg: #166534;
    --color-confirmed-bg: #DCFCE7;
    --color-confirmed-block: #F6FBF7;
    --color-completed-fg: #334155;
    --color-completed-bg: #E2E8F0;
    --color-cancelled-fg: #57534E;
    --color-cancelled-bg: #F5F5F4;
    --color-noshow-fg: #991B1B;
    --color-noshow-bg: #FEE2E2;
}

@layer base {
    body {
        background-color: var(--color-bg);
        color: var(--color-fg);
    }

    /* Anillo de foco único: tinta con separación blanca. Visible sobre papel
       y sobre el botón tinta sin necesidad de un sexto color. */
    :focus-visible {
        outline: 2px solid var(--color-fg);
        outline-offset: 2px;
    }

    a {
        text-underline-offset: 3px;
    }
}

@utility tnum {
    font-variant-numeric: tabular-nums;
}

/* Micro-etiqueta: solo para metadatos operativos (fechas de agrupación,
   encabezados de tabla, etiquetas de dato). Nunca como kicker decorativo. */
@utility micro {
    font-size: 12px;
    line-height: 16px;
    font-weight: 500;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--color-muted);
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}
```

- [ ] **Step 2: Compilar y verificar que los tokens salen al CSS**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test bash -lc "grep -c 'ECEDE8' public/build/assets/app-*.css"
```

Esperado: build OK y al menos una ocurrencia de `ECEDE8`.

- [ ] **Step 3: Confirmar que la suite sigue verde**

```bash
docker compose exec -T laravel.test php artisan test
```

Esperado: 542 passed. El cambio es solo de CSS pero el build alimenta `@vite`, y sin `public/build` toda página Inertia falla.

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css
git commit -m "feat: add the Turno design tokens to the Tailwind theme"
```

---

## Task 3: Primitivas de formulario, superficie y estado

**Files:**
- Create: `resources/js/Components/ui/icons.jsx`
- Create: `resources/js/Components/ui/Button.jsx`
- Create: `resources/js/Components/ui/IconButton.jsx`
- Create: `resources/js/Components/ui/Field.jsx`
- Create: `resources/js/Components/ui/Surface.jsx`
- Create: `resources/js/Components/ui/PageHeader.jsx`
- Create: `resources/js/Components/ui/StatusBadge.jsx`
- Create: `resources/js/Components/ui/EmptyState.jsx`
- Create: `resources/js/Components/ui/Alert.jsx`
- Create: `resources/js/Components/ui/TableShell.jsx`
- Create: `resources/js/Components/ui/StatCard.jsx`
- Modify: `resources/js/Components/InputError.jsx`

**Interfaces:**
- Consumes: tokens de la Task 2.
- Produces:
  - `<Button variant="primary|secondary|danger" size="sm|md" as={Link|'button'} {...props} />`
  - `<IconButton label="…" icon={Icon} {...props} />` — `label` obligatorio, se emite como `aria-label`
  - `<FormField id label error hint>{children}</FormField>`, `<Input />`, `<Select />`, `<Textarea />`
  - `<Surface as="div" className>` — blanco, borde 1px, radio 6
  - `<PageHeader title subtitle actions />`
  - `<StatusBadge tone="pending|confirmed|completed|cancelled|noshow|neutral" icon label />`
  - `<EmptyState icon title description action />`
  - `<Alert tone="warning|info" title>{children}</Alert>` — sin spine
  - `<TableShell>{rows}</TableShell>` — superficie con filete entre hijos
  - `<StatCard label value hint tone />`
  - Iconos exportados: `ClockIcon`, `CheckIcon`, `CheckCircleIcon`, `CrossIcon`, `SlashCircleIcon`, `PlusIcon`, `ChevronDownIcon`, `ChevronLeftIcon`, `ArrowRightIcon`, `MoreIcon`, `MailIcon`, `WarningIcon`, `MenuIcon`, `CalendarIcon`, `ServiceIcon`, `PeopleIcon`, `HolidayIcon`, `SettingsIcon`, `GridIcon`, `ExternalIcon`

- [ ] **Step 1: Escribir el set de iconos**

Un solo tratamiento: `viewBox="0 0 16 16"`, `fill="none"`, `stroke="currentColor"`, `stroke-width` 1.5–1.6, extremos redondeados. Ejemplo del patrón exacto que deben seguir los veinte:

```jsx
function Icon({ size = 16, children, ...props }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {children}
        </svg>
    );
}

export const ClockIcon = (p) => <Icon {...p}><circle cx="8" cy="8" r="5.8" /><path d="M8 4.6V8l2.4 1.5" /></Icon>;
export const CheckIcon = (p) => <Icon {...p}><path d="M3.2 8.4l3.3 3.3L12.8 5.2" /></Icon>;
export const CheckCircleIcon = (p) => <Icon {...p}><circle cx="8" cy="8" r="5.8" /><path d="M5.3 8.2l2 2 3.5-4" /></Icon>;
export const CrossIcon = (p) => <Icon {...p}><path d="M4.4 4.4l7.2 7.2M11.6 4.4l-7.2 7.2" /></Icon>;
export const SlashCircleIcon = (p) => <Icon {...p}><circle cx="8" cy="8" r="5.8" /><path d="M3.9 12.1L12.1 3.9" /></Icon>;
```

Los quince restantes se copian de los artboards aprobados: `.design/Main.dc.html` (navegación, más, chevron, plus), `.design/Demo.dc.html` (mail, externo, warning), `.design/MobileBook.dc.html` (chevron izquierda, menú).

- [ ] **Step 2: Escribir `Button` e `IconButton`**

```jsx
import { Link } from '@inertiajs/react';

const VARIANTS = {
    primary: 'border-fg bg-fg text-bg',
    secondary: 'border-border bg-surface text-fg',
    danger: 'border-noshow-fg bg-surface text-noshow-fg',
};

const SIZES = { sm: 'h-[30px] px-3 text-[13px]', md: 'h-[34px] px-3.5 text-[13px]', lg: 'h-11 px-5 text-[15px]' };

export default function Button({ variant = 'secondary', size = 'md', as, className = '', ...props }) {
    const Tag = as ?? (props.href ? Link : 'button');
    const classes = `inline-flex items-center justify-center gap-1.5 rounded border font-medium disabled:opacity-50 ${VARIANTS[variant]} ${SIZES[size]} ${className}`;
    return <Tag className={classes} {...props} />;
}
```

```jsx
export default function IconButton({ label, icon: Icon, className = '', ...props }) {
    if (!label) {
        throw new Error('IconButton requiere `label`: es su nombre accesible.');
    }
    return (
        <button
            type="button"
            aria-label={label}
            className={`inline-flex h-11 w-11 items-center justify-center rounded text-muted ${className}`}
            {...props}
        >
            <Icon />
        </button>
    );
}
```

`h-11 w-11` son 44px: es el piso táctil obligatorio.

- [ ] **Step 3: Escribir `Field.jsx`**

```jsx
import InputError from '../InputError';

export function Input({ className = '', ...props }) {
    return <input className={`block h-[42px] w-full rounded border border-border bg-surface px-3 text-[15px] placeholder:text-fg-placeholder ${className}`} {...props} />;
}

export function Select({ className = '', children, ...props }) {
    return <select className={`block h-[42px] w-full rounded border border-border bg-surface px-3 text-[15px] ${className}`} {...props}>{children}</select>;
}

export function Textarea({ className = '', ...props }) {
    return <textarea className={`block w-full rounded border border-border bg-surface px-3 py-2 text-[15px] ${className}`} {...props} />;
}

export function FormField({ id, label, error, hint, children }) {
    const hintId = hint ? `${id}-hint` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-[13px] font-medium">{label}</label>
            {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
            {hint && <p id={hintId} className="mt-1.5 text-xs leading-[18px] text-muted">{hint}</p>}
            <InputError id={errorId} message={error} />
        </div>
    );
}
```

`FormField` recibe `children` como función para poder inyectar `id` y `aria-describedby` en el control sin clonar elementos.

- [ ] **Step 4: Actualizar `InputError` para aceptar `id`**

```jsx
export default function InputError({ id, message }) {
    if (!message) {
        return null;
    }

    return <p id={id} className="mt-1.5 text-[13px] text-noshow-fg">{message}</p>;
}
```

- [ ] **Step 5: Escribir `Surface`, `PageHeader`, `StatusBadge`, `EmptyState`, `Alert`, `TableShell`, `StatCard`**

```jsx
// Surface.jsx
export default function Surface({ as: Tag = 'div', className = '', children, ...props }) {
    return <Tag className={`rounded-md border border-border bg-surface ${className}`} {...props}>{children}</Tag>;
}
```

```jsx
// PageHeader.jsx
export default function PageHeader({ title, subtitle, actions }) {
    return (
        <div className="mb-5 flex items-start justify-between gap-6">
            <div>
                <h1 className="text-2xl font-semibold leading-8 tracking-[-0.02em]">{title}</h1>
                {subtitle && <p className="mt-0.5 text-[13px] leading-5 text-muted">{subtitle}</p>}
            </div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );
}
```

```jsx
// StatusBadge.jsx
const TONES = {
    pending: 'bg-pending-bg text-pending-fg',
    confirmed: 'bg-confirmed-bg text-confirmed-fg',
    completed: 'bg-completed-bg text-completed-fg',
    cancelled: 'bg-cancelled-bg text-cancelled-fg',
    noshow: 'bg-noshow-bg text-noshow-fg',
    neutral: 'bg-cancelled-bg text-muted',
};

export default function StatusBadge({ tone, icon: Icon, label }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded px-2 py-[3px] text-xs font-medium leading-4 ${TONES[tone]}`}>
            <Icon size={13} />
            {label}
        </span>
    );
}
```

```jsx
// Alert.jsx  — superficie tintada, SIN spine
import { WarningIcon } from './icons';

export default function Alert({ tone = 'warning', title, children }) {
    const skin = tone === 'warning'
        ? 'border-pending-border bg-pending-block text-pending-strong'
        : 'border-border bg-surface text-fg-body';
    return (
        <div className={`rounded-md border p-4 ${skin}`}>
            {title && (
                <div className="flex items-center gap-2">
                    <WarningIcon size={15} className={tone === 'warning' ? 'text-pending-fg' : 'text-muted'} />
                    <span className="micro text-pending-fg">{title}</span>
                </div>
            )}
            <div className="mt-1.5 text-[14px] leading-[21px]">{children}</div>
        </div>
    );
}
```

```jsx
// EmptyState.jsx
export default function EmptyState({ icon: Icon, title, description, action }) {
    return (
        <div className="rounded-md border border-border bg-surface px-6 py-7 text-center">
            {Icon && <Icon size={22} className="mx-auto text-muted opacity-45" />}
            <div className="mt-2 text-[15px] font-medium">{title}</div>
            {description && <p className="mx-auto mt-1 max-w-[280px] text-[13px] leading-5 text-muted">{description}</p>}
            {action && <div className="mt-4 flex justify-center">{action}</div>}
        </div>
    );
}
```

```jsx
// TableShell.jsx  — filas separadas por filete, el spine lo pone cada fila
export default function TableShell({ className = '', children }) {
    return <div className={`overflow-hidden rounded-md border border-border bg-surface [&>*+*]:border-t [&>*+*]:border-border ${className}`}>{children}</div>;
}
```

```jsx
// StatCard.jsx
export default function StatCard({ label, value, hint, tone }) {
    const colour = tone === 'pending' ? 'text-pending-fg' : '';
    return (
        <div>
            <div className={`micro ${tone === 'pending' ? 'text-pending-fg' : ''}`}>{label}</div>
            <div className="mt-0.5 flex items-baseline gap-1.5">
                <span className={`tnum text-[26px] font-semibold leading-8 tracking-[-0.03em] ${colour}`}>{value}</span>
                {hint && <span className="text-[13px] text-muted">{hint}</span>}
            </div>
        </div>
    );
}
```

- [ ] **Step 6: Compilar**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
```

Esperado: build OK. Ninguna página las usa todavía, así que el bundle apenas crece.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/ui resources/js/Components/InputError.jsx
git commit -m "feat: add the base UI primitives"
```

---

## Task 4: Primitivas de overlay con contrato de accesibilidad

**Files:**
- Create: `resources/js/Components/ui/Modal.jsx`
- Create: `resources/js/Components/ui/ConfirmDialog.jsx`
- Create: `resources/js/Components/ui/Drawer.jsx`
- Create: `resources/js/Components/ui/Toast.jsx`

**Interfaces:**
- Consumes: `Button`, `IconButton`, iconos.
- Produces:
  - `<Modal open onClose title>{children}</Modal>`
  - `<ConfirmDialog open onCancel onConfirm title description confirmLabel tone />`
  - `<Drawer open onClose title>{children}</Drawer>`
  - `<Toast message onDismiss />`

- [ ] **Step 1: Escribir `Modal` sobre `<dialog>` nativo**

El `<dialog>` nativo da trampa de foco, `Escape` y fondo inerte. **Lo que sigue es responsabilidad nuestra** y no puede omitirse: nombre accesible, foco inicial deliberado y restauración del foco al disparador.

```jsx
import { useEffect, useId, useRef } from 'react';
import IconButton from './IconButton';
import { CrossIcon } from './icons';

export default function Modal({ open, onClose, title, initialFocusRef, children }) {
    const ref = useRef(null);
    const returnFocusTo = useRef(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;

        if (open && !dialog.open) {
            returnFocusTo.current = document.activeElement;
            dialog.showModal();
            (initialFocusRef?.current ?? dialog.querySelector('[data-autofocus]') ?? dialog).focus();
        }

        if (!open && dialog.open) {
            dialog.close();
        }
    }, [open, initialFocusRef]);

    // `cancel` cubre Escape; `close` cubre cualquier cierre. El foco vuelve
    // siempre al elemento que abrió el diálogo.
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;

        const handleClose = () => {
            onClose();
            returnFocusTo.current?.focus?.();
        };

        dialog.addEventListener('close', handleClose);
        return () => dialog.removeEventListener('close', handleClose);
    }, [onClose]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            className="w-full max-w-lg rounded-md border border-border bg-surface p-0 text-fg shadow-[0_16px_48px_rgba(25,26,23,.18)] backdrop:bg-fg/30"
        >
            <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <h2 id={titleId} className="text-[17px] font-semibold tracking-[-0.015em]">{title}</h2>
                <IconButton label="Cerrar" icon={CrossIcon} onClick={() => ref.current?.close()} className="-mr-3 -mt-2" />
            </div>
            <div className="px-5 py-4">{children}</div>
        </dialog>
    );
}
```

- [ ] **Step 2: Escribir `ConfirmDialog` con el foco en la acción segura**

```jsx
import { useRef } from 'react';
import Modal from './Modal';
import Button from './Button';

export default function ConfirmDialog({ open, onCancel, onConfirm, title, description, confirmLabel, tone = 'danger' }) {
    // El foco arranca en Cancelar, nunca en la acción destructiva.
    const cancelRef = useRef(null);

    return (
        <Modal open={open} onClose={onCancel} title={title} initialFocusRef={cancelRef}>
            <p className="text-[15px] leading-6 text-fg-body">{description}</p>
            <div className="mt-5 flex justify-end gap-2">
                <Button ref={cancelRef} data-autofocus variant="secondary" onClick={onCancel}>Cancelar</Button>
                <Button variant={tone} onClick={onConfirm}>{confirmLabel}</Button>
            </div>
        </Modal>
    );
}
```

- [ ] **Step 3: Escribir `Drawer`**

Mismo contrato que `Modal`, con posición y transición lateral. Reutiliza `Modal` internamente montándolo con clases de posición:

```jsx
import Modal from './Modal';

export default function Drawer({ open, onClose, title, children }) {
    return (
        <div className="[&>dialog]:m-0 [&>dialog]:h-full [&>dialog]:max-h-none [&>dialog]:max-w-[280px] [&>dialog]:rounded-none [&>dialog]:border-l-0">
            <Modal open={open} onClose={onClose} title={title}>{children}</Modal>
        </div>
    );
}
```

- [ ] **Step 4: Escribir `Toast`**

```jsx
import { useEffect } from 'react';

export default function Toast({ message, onDismiss, duration = 4000 }) {
    useEffect(() => {
        if (!message) return;
        const timer = setTimeout(onDismiss, duration);
        return () => clearTimeout(timer);
    }, [message, onDismiss, duration]);

    if (!message) return null;

    return (
        <div
            role="status"
            className="fixed bottom-5 left-5 z-50 rounded-md border border-border bg-surface px-4 py-3 text-[14px] shadow-[0_12px_32px_rgba(25,26,23,.16)]"
        >
            {message}
        </div>
    );
}
```

- [ ] **Step 5: Compilar**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
```

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/ui
git commit -m "feat: add dialog, drawer and toast primitives"
```

---

## Task 5: Componentes de dominio de reservas

**Files:**
- Create: `resources/js/Components/domain/BookingStatusBadge.jsx`
- Create: `resources/js/Components/domain/PaymentStatusBadge.jsx`
- Create: `resources/js/Components/domain/BookingActions.jsx`
- Create: `resources/js/Components/domain/SlotPicker.jsx`

**Interfaces:**
- Consumes: `StatusBadge`, `Button`, `IconButton`, `ConfirmDialog`, iconos.
- Produces:
  - `<BookingStatusBadge status="pending|confirmed|cancelled|completed|no_show" />`
  - `<PaymentStatusBadge status="pending|approved|rejected|expired" />`
  - `<BookingActions booking basePath onAction />` — devuelve los botones válidos para el estado
  - `<SlotPicker slots value onChange columns />` — `slots` es `[{starts_at}]`

- [ ] **Step 1: Escribir los dos badges**

```jsx
// BookingStatusBadge.jsx
import StatusBadge from '../ui/StatusBadge';
import { ClockIcon, CheckIcon, CheckCircleIcon, CrossIcon, SlashCircleIcon } from '../ui/icons';

const MAP = {
    pending:   { tone: 'pending',   icon: ClockIcon,        label: 'Pendiente' },
    confirmed: { tone: 'confirmed', icon: CheckIcon,        label: 'Confirmada' },
    completed: { tone: 'completed', icon: CheckCircleIcon,  label: 'Completada' },
    cancelled: { tone: 'cancelled', icon: CrossIcon,        label: 'Cancelada' },
    no_show:   { tone: 'noshow',    icon: SlashCircleIcon,  label: 'Ausencia' },
};

export default function BookingStatusBadge({ status }) {
    const config = MAP[status];
    if (!config) return null;
    return <StatusBadge {...config} />;
}

export const BOOKING_SPINE = {
    pending: 'bg-pending-fg',
    confirmed: 'bg-confirmed-fg',
    completed: 'bg-completed-fg',
    cancelled: 'bg-cancelled-fg',
    no_show: 'bg-noshow-fg',
};
```

```jsx
// PaymentStatusBadge.jsx  — siempre prefijado con "Seña"
import StatusBadge from '../ui/StatusBadge';
import { ClockIcon, CheckIcon, CrossIcon, SlashCircleIcon } from '../ui/icons';

const MAP = {
    pending:  { tone: 'pending',   icon: ClockIcon,       label: 'Seña pendiente' },
    approved: { tone: 'confirmed', icon: CheckIcon,       label: 'Seña pagada' },
    rejected: { tone: 'noshow',    icon: CrossIcon,       label: 'Seña rechazada' },
    expired:  { tone: 'cancelled', icon: SlashCircleIcon, label: 'Seña vencida' },
};

export default function PaymentStatusBadge({ status }) {
    const config = MAP[status];
    if (!config) return null;
    return <StatusBadge {...config} />;
}
```

- [ ] **Step 2: Escribir `BookingActions`**

Las transiciones válidas por estado salen de `app/Policies/BookingPolicy.php` y de las rutas de `routes/dashboard.php`. **La Policy sigue siendo la autoridad**: esto solo evita ofrecer un botón que el servidor va a rechazar.

```jsx
import { useState } from 'react';
import { router } from '@inertiajs/react';
import Button from '../ui/Button';
import ConfirmDialog from '../ui/ConfirmDialog';

const CONFIRMATIONS = {
    cancel:   { title: 'Cancelar la reserva', description: 'La reserva pasa a cancelada y el horario vuelve a quedar libre. No se puede deshacer.', confirmLabel: 'Cancelar la reserva' },
    'no-show': { title: 'Marcar como ausencia', description: 'Queda registrado que la persona no se presentó. No se puede deshacer.', confirmLabel: 'Marcar ausencia' },
};

export default function BookingActions({ booking, onReschedule }) {
    const [pending, setPending] = useState(null);

    const post = (action) => router.post(`/dashboard/bookings/${booking.id}/${action}`, {}, { preserveScroll: true });

    const act = (action) => (CONFIRMATIONS[action] ? setPending(action) : post(action));

    const open = ['pending', 'confirmed'].includes(booking.status);

    return (
        <>
            {booking.status === 'pending' && <Button size="sm" variant="primary" onClick={() => post('confirm')}>Confirmar</Button>}
            {booking.status === 'confirmed' && <Button size="sm" onClick={() => post('complete')}>Completar</Button>}
            {booking.status === 'confirmed' && <Button size="sm" onClick={() => act('no-show')}>Ausencia</Button>}
            {open && <Button size="sm" onClick={onReschedule}>Reprogramar</Button>}
            {open && <Button size="sm" variant="danger" onClick={() => act('cancel')}>Cancelar</Button>}

            <ConfirmDialog
                open={pending !== null}
                onCancel={() => setPending(null)}
                onConfirm={() => { post(pending); setPending(null); }}
                {...(CONFIRMATIONS[pending] ?? {})}
            />
        </>
    );
}
```

- [ ] **Step 3: Escribir `SlotPicker`**

```jsx
export default function SlotPicker({ slots, value, onChange, columns = 6 }) {
    if (slots.length === 0) {
        return <p className="text-[13px] leading-5 text-muted">No hay horarios libres ese día.</p>;
    }

    return (
        <div role="radiogroup" aria-label="Horario disponible" className={`grid gap-2 grid-cols-3 sm:grid-cols-4 lg:grid-cols-${columns}`}>
            {slots.map((slot) => {
                const selected = value === slot.starts_at;
                const label = new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                return (
                    <button
                        key={slot.starts_at}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChange(slot.starts_at)}
                        className={`tnum flex h-12 items-center justify-center rounded border text-[15px] font-medium ${selected ? 'border-fg bg-fg text-bg' : 'border-border bg-surface'}`}
                    >
                        {label}
                    </button>
                );
            })}
        </div>
    );
}
```

Los chips miden 48px de alto: por encima del piso táctil de 44px.

- [ ] **Step 4: Compilar y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
git add resources/js/Components/domain
git commit -m "feat: add booking status, action and slot picker components"
```

---

## Task 6: Expandir el `DemoSeeder` (TDD backend)

**Files:**
- Modify: `database/seeders/DemoSeeder.php`
- Create: `tests/Feature/Seeders/DemoSeederTest.php`

**Interfaces:**
- Consumes: nada del frontend.
- Produces: dataset estable que consumen las Tasks 10, 12, 13, 18, 19 y 20 para verificación en navegador.

- [ ] **Step 1: Escribir los tests del dataset**

```php
<?php

namespace Tests\Feature\Seeders;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): void
    {
        $this->seed(DemoSeeder::class);
    }

    public function test_it_seeds_two_businesses_and_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(2, Business::count());
        $this->assertSame(23, Booking::withoutGlobalScope(BusinessScope::class)->count());
    }

    public function test_no_seeded_booking_or_payment_is_pending(): void
    {
        $this->seed();

        $this->assertSame(
            0,
            Booking::withoutGlobalScope(BusinessScope::class)->where('status', BookingStatus::Pending)->count(),
            'Una reserva pendiente sembrada se cancelaría sola minutos después del reinicio.'
        );

        $this->assertSame(
            0,
            Payment::withoutGlobalScope(BusinessScope::class)->where('status', PaymentStatus::Pending)->count()
        );
    }

    public function test_the_four_stable_states_are_present(): void
    {
        $this->seed();

        foreach ([BookingStatus::Confirmed, BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::NoShow] as $status) {
            $this->assertTrue(
                Booking::withoutGlobalScope(BusinessScope::class)->where('status', $status)->exists(),
                "Falta el estado {$status->value} en el dataset sembrado."
            );
        }
    }

    public function test_exactly_two_services_require_a_deposit(): void
    {
        $this->seed();

        $withDeposit = Service::withoutGlobalScope(BusinessScope::class)->whereNotNull('deposit_amount')->pluck('name')->sort()->values()->all();

        $this->assertSame(['Coloración', 'Grabación de demo'], $withDeposit);
    }

    public function test_every_seeded_payment_has_a_matching_provider_row(): void
    {
        $this->seed();

        $payments = Payment::withoutGlobalScope(BusinessScope::class)->get();

        $this->assertCount(3, $payments);

        foreach ($payments as $payment) {
            $this->assertDatabaseHas('simulated_provider_payments', ['external_id' => $payment->external_id]);
        }
    }

    public function test_seeding_sends_no_mail(): void
    {
        Notification::fake();

        $this->seed();

        Notification::assertNothingSent();
    }

    public function test_todays_bookings_fall_inside_their_employee_schedule_and_never_overlap(): void
    {
        $this->seed();

        $business = Business::where('slug', 'peluqueria-demo')->firstOrFail();
        $today = CarbonImmutable::now($business->timezone)->startOfDay();

        $bookings = Booking::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->whereBetween('starts_at', [$today->utc(), $today->addDay()->utc()])
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(6, $bookings);

        $previousEnd = null;

        foreach ($bookings as $booking) {
            $start = $booking->starts_at->setTimezone($business->timezone);
            $end = $booking->ends_at->setTimezone($business->timezone);

            $this->assertGreaterThanOrEqual(9, $start->hour, 'Una reserva arranca antes de las 09:00.');
            $this->assertLessThanOrEqual(18 * 60, $end->hour * 60 + $end->minute, 'Una reserva termina después de las 18:00.');

            if ($previousEnd !== null) {
                $this->assertTrue($start->greaterThanOrEqualTo($previousEnd), 'Dos reservas de hoy se superponen; el riel es de una sola columna.');
            }

            $previousEnd = $end;
        }
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

```bash
docker compose exec -T laravel.test php artisan test --filter=DemoSeederTest
```

Esperado: FAIL. El seeder actual siembra 0 reservas y 0 pagos.

- [ ] **Step 3: Reescribir `DemoSeeder`**

Sigue §10.2 de la spec al pie de la letra. Puntos que no se pueden equivocar:

- **Factories, nunca Actions.** `CreateBooking` rechaza horarios pasados y dispara `BookingCreated`.
- Envolver todo en `WithoutModelEvents` (el trait ya está en `DatabaseSeeder`; agregarlo también acá).
- `starts_at`/`ends_at` se calculan como hora de pared en `America/Argentina/Buenos_Aires` y se persisten con `->utc()`.
- Crear una fila de `BookingStatusHistory` por reserva con `from_status: null` y el estado inicial, más una segunda fila para las que transicionaron (`cancelled`, `completed`, `no_show`, y las `confirmed` que vinieron de `pending` por pago).
- Horarios: Ana y Beto los siete días 09:00–18:00; Carla lunes a viernes. Pausa 13:00–14:00 para los tres.
- Las dos reservas de Estudio se ajustan al día hábil más cercano.
- Tres pagos, todos `approved`, cada uno con su fila en `simulated_provider_payments`.

Reservas de hoy en Peluquería, exactamente:

```php
[
    ['09:00', 'Corte de cabello', 'ana',  'marina',  BookingStatus::Confirmed],
    ['10:00', 'Coloración',       'ana',  'lucia',   BookingStatus::Confirmed],  // con pago aprobado
    ['12:00', 'Corte de cabello', 'beto', 'rodrigo', BookingStatus::Confirmed],
    ['15:00', 'Manicura',         'ana',  'rodrigo', BookingStatus::Confirmed],
    ['16:30', 'Masaje',           'beto', 'marina',  BookingStatus::Confirmed],
    ['17:30', 'Depilación',       'ana',  'julian',  BookingStatus::Cancelled],
]
```

- [ ] **Step 4: Correr los tests hasta verde**

```bash
docker compose exec -T laravel.test php artisan test --filter=DemoSeederTest
```

Esperado: PASS, 7 tests.

- [ ] **Step 5: Sembrar de verdad y mirar los datos**

```bash
docker compose exec -T laravel.test php artisan migrate:fresh --force
docker compose exec -T laravel.test php artisan db:seed --class=DemoSeeder
docker compose exec -T laravel.test php artisan tinker --execute="echo \App\Models\Booking::withoutGlobalScopes()->count();"
```

Esperado: `23`.

- [ ] **Step 6: Suite completa y Pint**

```bash
docker compose exec -T laravel.test php artisan test
docker compose exec -T laravel.test vendor/bin/pint --test
```

- [ ] **Step 7: Commit**

```bash
git add database/seeders/DemoSeeder.php tests/Feature/Seeders/DemoSeederTest.php
git commit -m "feat: seed a stable demo dataset with customers, bookings and paid deposits"
```

---

## Task 7: Shell de staff

**Files:**
- Modify: `resources/js/Components/DashboardLayout.jsx`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`

**Interfaces:**
- Consumes: `Drawer`, `IconButton`, iconos.
- Produces: `<DashboardLayout>{children}</DashboardLayout>` con barra lateral colapsable, drawer móvil y pie con contacto. Todas las páginas del panel lo usan.
- Props compartidas nuevas: `auth.user.email`, `auth.business = { id, name } | null`.

- [ ] **Step 1: Test del middleware**

Agregar a `tests/Feature/Middleware/HandleInertiaRequestsTest.php`:

```php
    public function test_it_shares_the_business_for_staff(): void
    {
        $business = Business::factory()->create(['name' => 'Peluquería Demo']);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);

        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.business.name', 'Peluquería Demo')
                ->has('auth.user.email'));
    }
```

- [ ] **Step 2: Correr y verificar que falla**

```bash
docker compose exec -T laravel.test php artisan test --filter=HandleInertiaRequestsTest
```

Esperado: FAIL, `auth.business` no existe.

- [ ] **Step 3: Ampliar `share()`**

```php
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role?->value,
                ] : null,
                'business' => $request->user()?->business ? [
                    'id' => $request->user()->business->id,
                    'name' => $request->user()->business->name,
                ] : null,
            ],
```

- [ ] **Step 4: Test en verde**

```bash
docker compose exec -T laravel.test php artisan test --filter=HandleInertiaRequestsTest
```

- [ ] **Step 5: Reescribir `DashboardLayout`**

Anatomía exacta en `.design/Main.dc.html` y §6.1 de la spec. Requisitos:

- Riel de 240px: bloque de identidad del negocio arriba, navegación en el medio, usuario abajo.
- Navegación consciente del rol con la tabla de §6.1. `employee` no ve Personal, Feriados ni Configuración.
- Colapso a 64px entre 1024 y 1279px, estado en `localStorage` bajo la clave `reservahub.sidebar`, envuelto en `try/catch` porque el acceso puede lanzar.
- Bajo 768px: `Drawer` detrás de un `IconButton` con `label="Abrir navegación"`.
- Landmarks: `<nav aria-label="Secciones">` y `<main>`.
- Pie dentro de la columna de contenido, con `margin-top:auto`, aviso de demo, enlace a `/como-funciona` y `mailto:lucianogonzalez12004@gmail.com`.

- [ ] **Step 6: Compilar y mirar en el navegador**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
```

Abrir `http://localhost:8180/dashboard` como `owner@reservahub.test` / `password`. Verificar a 1440, 1024 y 390: riel completo, riel colapsado, drawer. En el drawer comprobar `Escape`, trampa de foco y que el foco vuelve al botón `≡`.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/DashboardLayout.jsx app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Middleware/HandleInertiaRequestsTest.php
git commit -m "feat: rebuild the staff shell as a collapsible sidebar"
```

---

## Task 8: Shell público y familia de autenticación

**Files:**
- Modify: `resources/js/Components/PublicLayout.jsx`
- Modify: `resources/js/Components/AuthCard.jsx`
- Modify: `resources/js/Pages/Auth/{Login,Register,ForgotPassword,ResetPassword,VerifyEmail}.jsx`
- Modify: `resources/js/Pages/Invitations/{Accept,Unavailable}.jsx`

**Interfaces:**
- Consumes: primitivas de la Task 3.
- Produces: `<PublicLayout>` con cabecera y pie **siempre visibles**, con o sin sesión. `<AuthCard title>` conserva su API.

- [ ] **Step 1: Reescribir `PublicLayout`**

Anatomía en §6.2 y `.design/Landing.dc.html`. El hueco que cierra: hoy la navegación se renderiza **solo si hay sesión**, así que un invitado en `/negocios` no tiene forma de volver ni de entrar.

- Cabecera siempre: wordmark, `DEMO PÚBLICA` en versalitas (texto, no pastilla), `Negocios`, `Cómo funciona la demo`, y `Ingresar`/`Crear cuenta` o el menú de cuenta con `Mis reservas`.
- Pie: bloque de contacto con la dirección visible y `mailto`, enlaces de la demo, y `No es un servicio comercial en funcionamiento.`

- [ ] **Step 2: Reescribir `AuthCard`**

Cabecera pública simplificada, tarjeta de 460px centrada sobre papel, pie con aviso de demo y contacto. Conserva `title` y `children`.

- [ ] **Step 3: Rehacer las cinco páginas de auth**

Todas con `FormField` + `Input` + `InputError`, etiquetas asociadas por `htmlFor`/`id`, errores por `aria-describedby`. **Ninguna ruta, validación ni Action se modifica.**

- [ ] **Step 4: Agregar el aviso de demo a `Register`, y solo ahí**

Bajo el título, `<Alert tone="warning" title="Datos ficticios">` con el texto de §8.3: demo pública compartida, nombre y correo inventados, y una contraseña descartable que **no** se use en ningún otro servicio.

El selector de tipo de cuenta pasa de dos radios sueltos a dos tarjetas seleccionables con `role="radiogroup"` y radios reales dentro.

Bajo el campo de correo, una línea: los correos llegan a un buzón compartido que cualquiera puede abrir.

**`Login` no lleva aviso.**

- [ ] **Step 5: Rehacer las dos páginas de invitación**

Heredan la familia sin diseño propio. Esfuerzo deliberadamente proporcionado.

- [ ] **Step 6: Compilar, verificar y correr la suite**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=Auth
docker compose exec -T laravel.test php artisan test --filter=Invitation
```

En navegador: `/login`, `/register`, `/forgot-password` a 1440 y 390. Comprobar que un invitado en `/negocios` ahora ve cabecera y pie.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/PublicLayout.jsx resources/js/Components/AuthCard.jsx resources/js/Pages/Auth resources/js/Pages/Invitations
git commit -m "feat: rebuild the public shell and the authentication family"
```

---

## Task 9: Contador de reinicio diario

**Files:**
- Create: `resources/js/Components/domain/DemoResetCountdown.jsx`

**Interfaces:**
- Produces: `<DemoResetCountdown className />` — renderiza texto plano tipo `8 h 42 min`. Lo consumen la Task 10 (Home) y la Task 11 (`/como-funciona`).

- [ ] **Step 1: Escribir el componente con el contrato exacto de §7.3**

```jsx
import { useEffect, useState } from 'react';

const DEMO_TIMEZONE = 'America/Argentina/Buenos_Aires';
const REFRESH_MS = 30_000;

// El horario del reinicio vive acá y en la programación real que corre en el
// servidor. Si cambia una, hay que cambiar la otra.
const RESET_HOUR = 0;

function secondsUntilReset() {
    // formatToParts, nunca format(): parsear una cadena formateada por locale
    // es frágil y algunos locales representan la medianoche como 24:00.
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: DEMO_TIMEZONE,
        hourCycle: 'h23',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(new Date());

    const read = (type) => Number(parts.find((part) => part.type === type)?.value ?? 0);

    const elapsed = read('hour') * 3600 + read('minute') * 60 + read('second');
    const target = RESET_HOUR * 3600;

    return elapsed <= target ? target - elapsed : 86_400 - elapsed + target;
}

function format(seconds) {
    // Por exceso: nunca mostrar "0 h 0 min" con segundos todavía por delante.
    const minutes = Math.ceil(seconds / 60);
    return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

export default function DemoResetCountdown({ className = '' }) {
    const [seconds, setSeconds] = useState(secondsUntilReset);

    useEffect(() => {
        const timer = setInterval(() => setSeconds(secondsUntilReset()), REFRESH_MS);
        return () => clearInterval(timer);
    }, []);

    // Sin aria-live: no interrumpe a lectores de pantalla cada minuto.
    // min-w evita que la franja se reacomode al pasar de "12 h 42 min" a "9 h 5 min".
    return <span className={`tnum inline-block min-w-[6.5ch] ${className}`}>{format(seconds)}</span>;
}
```

- [ ] **Step 2: Verificar el cálculo con el reloj del navegador en otra zona**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
```

En DevTools → Sensors, poner la zona en `Europe/Madrid` y en `Asia/Tokyo`. El valor mostrado tiene que ser **el mismo** en las tres zonas.

- [ ] **Step 3: Verificar que no hay salto de layout**

En DevTools, forzar el reloj cerca de un cambio de decena de minutos y comprobar que el ancho del contenedor no cambia.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/domain/DemoResetCountdown.jsx
git commit -m "feat: add the daily demo reset countdown"
```

---

## Task 10: Puente del Home y landing

**Files:**
- Create: `app/Http/Controllers/HomeController.php`
- Create: `tests/Feature/HomeTest.php`
- Create: `resources/js/Components/DemoStrip.jsx`
- Modify: `routes/web.php:7-9`
- Modify: `resources/js/Pages/Home.jsx`

**Interfaces:**
- Consumes: `DemoResetCountdown`, primitivas.
- Produces: prop `timeline` con el contrato de §8.1, o `null`.

- [ ] **Step 1: Escribir los tests del controlador**

```php
<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes fijo: la elegibilidad depende del día de la semana.
        $this->travelTo(CarbonImmutable::parse('2026-01-05 12:00', 'UTC'));
    }

    private function businessWithSchedule(string $name, DayOfWeek $day): Business
    {
        $business = Business::factory()->create(['name' => $name, 'timezone' => 'UTC', 'is_active' => true]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id, 'is_active' => true]);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => $day,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        return $business;
    }

    public function test_it_renders_without_a_timeline_when_no_business_qualifies(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home')->where('timeline', null));
    }

    public function test_it_skips_a_business_with_no_eligible_employee_today(): void
    {
        // "Alfa" ordena antes pero solo trabaja los martes; hoy es lunes.
        $this->businessWithSchedule('Alfa', DayOfWeek::Tuesday);
        $this->businessWithSchedule('Beta', DayOfWeek::Monday);

        $this->get('/')->assertInertia(fn ($page) => $page->where('timeline.business_name', 'Beta'));
    }

    public function test_an_inactive_business_never_qualifies(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $business->update(['is_active' => false]);

        $this->get('/')->assertInertia(fn ($page) => $page->where('timeline', null));
    }

    public function test_an_inactive_employee_does_not_make_a_business_eligible(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $business->users()->update(['is_active' => false]);

        $this->get('/')->assertInertia(fn ($page) => $page->where('timeline', null));
    }

    public function test_cancelled_bookings_are_not_occupied(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $employee = $business->users()->first();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => CarbonImmutable::parse('2026-01-05 10:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-01-05 10:30', 'UTC'),
            'status' => BookingStatus::Cancelled,
        ]);

        $this->get('/')->assertInertia(fn ($page) => $page->has('timeline.occupied', 0));
    }

    public function test_the_projection_leaks_nothing_private(): void
    {
        $business = $this->businessWithSchedule('Alfa', DayOfWeek::Monday);
        $employee = $business->users()->first();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'name' => 'Corte']);

        Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => CarbonImmutable::parse('2026-01-05 10:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-01-05 10:30', 'UTC'),
            'status' => BookingStatus::Confirmed,
        ]);

        $this->get('/')->assertInertia(function ($page) {
            $occupied = $page->toArray()['props']['timeline']['occupied'][0];

            $this->assertSame(
                ['starts_at', 'ends_at', 'duration_minutes', 'service_name'],
                array_keys($occupied),
                'La tira del Home solo puede proyectar geometría y el nombre del servicio.'
            );

            $this->assertArrayNotHasKey('slot_minutes', $page->toArray()['props']['timeline']);
        });
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

```bash
docker compose exec -T laravel.test php artisan test --filter=HomeTest
```

Esperado: FAIL, la ruta es una closure sin `timeline`.

- [ ] **Step 3: Escribir `HomeController`**

Acción única `__invoke`, mismo patrón que `DashboardController`. Reglas que los tests fijan:

- Ordenar negocios activos de forma determinista por nombre y **saltear** los que hoy no tienen ningún empleado activo con horario activo para el día de la semana actual.
- `timeline: null` solo si ninguno califica.
- **Prohibido** fijar `peluqueria-demo` en el código.
- Proyectar solo `starts_at`, `ends_at`, `duration_minutes` y `service_name` de las reservas **no canceladas** de ese empleado hoy, formateando las horas a `HH:MM` en la zona del negocio.
- Nunca proyectar identidad de cliente, id de reserva, estado ni campo de pago. Nunca `slot_minutes`.

Reemplazar la closure en `routes/web.php`:

```php
Route::get('/', HomeController::class)->name('home');
```

- [ ] **Step 4: Tests en verde**

```bash
docker compose exec -T laravel.test php artisan test --filter=HomeTest
```

- [ ] **Step 5: Escribir `DemoStrip`**

Franja informativa de tres columnas más enlace, **neutra**, sin ámbar: es información, no un estado de advertencia. Columnas: demo compartida / se restaura cada día con `<DemoResetCountdown />` / usá datos ficticios. Anatomía en `.design/Landing.dc.html`.

- [ ] **Step 6: Reescribir `Home.jsx`**

Estructura de §8.1 y `.design/Landing.dc.html`. Puntos críticos:

- Si `timeline === null`, **la tira no se renderiza** y la franja de demo sube a ocupar su lugar. El hero conserva título, párrafo y acciones.
- La tira dibuja bloques ocupados y pista neutra. El texto dice `sin reserva`. **Prohibido** escribir "libre", "disponible", "reservable" o contar turnos: no es salida de `AvailabilityService`.
- Posición y ancho de cada bloque salen de `starts_at`/`ends_at` contra `window`, a 2,074 px/min sobre 1120px.

- [ ] **Step 7: Compilar y revisar en navegador**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
```

Abrir `http://localhost:8180/` a 1440, 1024 y 390. Comprobar: la tira aparece con Peluquería Demo; en ningún ancho aparece la palabra "libre"; la franja apila bien en móvil; el contador no salta.

- [ ] **Step 8: Verificar el caso de fin de semana**

```bash
docker compose exec -T laravel.test php artisan tinker --execute="echo \Carbon\CarbonImmutable::now('America/Argentina/Buenos_Aires')->dayOfWeek;"
```

Si hoy es sábado o domingo, la tira igual tiene que renderizarse con Peluquería Demo, porque Estudio Demo no califica y la selección lo saltea.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/HomeController.php tests/Feature/HomeTest.php routes/web.php resources/js/Pages/Home.jsx resources/js/Components/DemoStrip.jsx
git commit -m "feat: rebuild the landing page over a real occupancy timeline"
```

---

## Task 11: Guía `/como-funciona`

**Files:**
- Create: `app/Http/Controllers/ComoFuncionaController.php`
- Create: `tests/Feature/ComoFuncionaTest.php`
- Create: `resources/js/Pages/ComoFunciona.jsx`
- Modify: `routes/public.php`
- Modify: `.env.example`

**Interfaces:**
- Consumes: `DemoResetCountdown`, primitivas.
- Produces: ruta `public.guide`, prop `mailUrl` (string o `null`).

- [ ] **Step 1: Test de la ruta**

```php
    public function test_the_guide_is_public(): void
    {
        $this->get('/como-funciona')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ComoFunciona'));
    }

    public function test_the_mailbox_cta_is_absent_without_configuration(): void
    {
        config(['app.demo_mail_url' => null]);

        $this->get('/como-funciona')
            ->assertInertia(fn ($page) => $page->where('mailUrl', null));
    }
```

- [ ] **Step 2: Correr y verificar que falla**

```bash
docker compose exec -T laravel.test php artisan test --filter=ComoFuncionaTest
```

- [ ] **Step 3: Registrar la ruta en `routes/public.php`**

Va acá, **no** en `routes/demo.php`: ese archivo se carga solo cuando el proveedor ligado es el simulado (`routes/web.php:18`) y la guía es producto permanente.

```php
Route::get('como-funciona', ComoFuncionaController::class)->name('public.guide');
```

Colocarla **fuera** del grupo con prefijo de slug, junto a `Route::get('negocios', …)`.

- [ ] **Step 4: Declarar la variable en `.env.example`**

Junto al bloque de `VITE_REVERB_*`, con el mismo tono de comentario:

```
# Buzón público de la demo. Se compila dentro del bundle y es público por
# definición: no es un secreto. Sin definir, el CTA no se muestra y el resto
# de la aplicación funciona igual. Cambiarlo exige volver a correr `pnpm build`.
VITE_DEMO_MAIL_URL=
```

- [ ] **Step 5: Escribir `ComoFunciona.jsx`**

Secciones en el orden de §8.2, con la anatomía de `.design/Demo.dc.html`. La numeración `01`–`05` de los recorridos está justificada porque son secuencias reales.

El CTA del buzón lee `import.meta.env.VITE_DEMO_MAIL_URL`; **si no está definida, no se renderiza**. Es `<a target="_blank" rel="noopener">`. **Prohibido** cualquier polling o comprobación de disponibilidad de Mailpit.

**Prohibido** mencionar PostgreSQL, locks, Redis, colas, nombres de jobs, HMAC, protocolo de Reverb, Docker, cron, systemd o Cloudflare.

- [ ] **Step 6: Compilar y revisar**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=ComoFuncionaTest
```

En navegador a 1440, 1024 y 390. Comprobar que el CTA del buzón **no** aparece (la variable está vacía en desarrollo).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ComoFuncionaController.php tests/Feature/ComoFuncionaTest.php resources/js/Pages/ComoFunciona.jsx routes/public.php .env.example
git commit -m "feat: add the shared demo guide at /como-funciona"
```

---

## Task 12: Panel operativo

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Create: `tests/Feature/Dashboard/DashboardMetricsTest.php`
- Create: `resources/js/Components/domain/DayRail.jsx`
- Modify: `resources/js/Pages/Dashboard/Index.jsx`

**Interfaces:**
- Consumes: `StatCard`, `EmptyState`, `Surface`, `BookingStatusBadge`, `BOOKING_SPINE`.
- Produces: props `business`, `metrics`, `today`, `attention` con la forma de §9.1. `<DayRail bookings window />`.

- [ ] **Step 1: Escribir los tests de métricas**

```php
    public function test_expiring_soon_excludes_an_already_expired_booking(): void
    {
        // Vencida hace un minuto pero todavía pending: el scheduler no corrió.
        $this->pendingBookingExpiringAt(CarbonImmutable::now()->subMinute());

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.expiring_soon', 0));
    }

    public function test_expiring_soon_includes_a_booking_inside_the_window(): void
    {
        $this->pendingBookingExpiringAt(CarbonImmutable::now()->addMinutes(10));

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.expiring_soon', 1));
    }

    public function test_the_attention_queue_never_lists_a_booking_twice(): void
    {
        $booking = $this->pendingBookingExpiringAt(CarbonImmutable::now()->addMinutes(5));

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(function ($page) use ($booking) {
                $ids = collect($page->toArray()['props']['attention'])->pluck('id');

                $this->assertSame(1, $ids->filter(fn ($id) => $id === $booking->id)->count());
                $this->assertSame('expiring_soon', $page->toArray()['props']['attention'][0]['kind']);
            });
    }

    public function test_today_is_resolved_in_the_business_timezone(): void
    {
        // 23:30 en Buenos Aires es 02:30 UTC del día siguiente. Si la ventana
        // se calculara en UTC, esta reserva caería fuera de "hoy".
        $this->travelTo(CarbonImmutable::parse('2026-01-05 23:30', 'America/Argentina/Buenos_Aires'));
        $this->confirmedBookingAt(CarbonImmutable::parse('2026-01-05 23:45', 'America/Argentina/Buenos_Aires'));

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.today_total', 1));
    }

    public function test_an_employee_only_sees_their_own_agenda(): void
    {
        $this->confirmedBookingAt(CarbonImmutable::now()->setTime(10, 0), $this->otherEmployee);

        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.today_total', 0));
    }

    public function test_a_business_never_sees_another_businesses_metrics(): void
    {
        $other = Business::factory()->create();
        Booking::factory()->create(['business_id' => $other->id, 'starts_at' => CarbonImmutable::now()->setTime(10, 0)]);

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.today_total', 0));
    }
```

Los helpers `pendingBookingExpiringAt`, `confirmedBookingAt` y las propiedades `$owner`, `$employee`, `$otherEmployee` se construyen en `setUp()` con factories, congelando el reloj como en la Task 1.

- [ ] **Step 2: Correr y verificar que falla**

```bash
docker compose exec -T laravel.test php artisan test --filter=DashboardMetricsTest
```

- [ ] **Step 3: Ampliar `DashboardController`**

Devolver la estructura de §9.1. Reglas que los tests fijan:

- La ventana "hoy" se resuelve en `business.timezone`, no en UTC.
- `expiring_soon` es `status = pending AND payment_expires_at > now() AND payment_expires_at <= now() + 15 min`. **La condición `> now()` no es opcional.**
- `awaiting_deposit` como métrica es el total de `pending`; el solapamiento con `expiring_soon` es intencional.
- En `attention` la clasificación es **excluyente y priorizada**: `expiring_soon` primero, `awaiting_deposit` recoge las pendientes restantes. Ninguna reserva aparece dos veces.
- `today` ordenado por `starts_at`, desempatando por `ends_at` y después por `id`.
- `employee` filtra todo por `employee_id = self`.

- [ ] **Step 4: Tests en verde**

```bash
docker compose exec -T laravel.test php artisan test --filter=DashboardMetricsTest
```

- [ ] **Step 5: Escribir `DayRail` con carriles deterministas**

```jsx
const PX_PER_MINUTE = 1.1;

function toMinutes(isoOrHhmm) {
    const date = new Date(isoOrHhmm);
    return date.getHours() * 60 + date.getMinutes();
}

// Racimos de solapes → asignación voraz de carril → ancho = 100% / carriles.
// Un racimo sin solapes conserva un solo carril y ocupa el ancho completo, así
// que los datos sembrados se ven igual que el artboard aprobado.
export function assignLanes(bookings) {
    const sorted = [...bookings].sort((a, b) =>
        toMinutes(a.starts_at) - toMinutes(b.starts_at)
        || toMinutes(a.ends_at) - toMinutes(b.ends_at)
        || a.id - b.id
    );

    const placed = [];
    let cluster = [];
    let clusterEnd = -Infinity;

    const flush = () => {
        const lanes = [];
        for (const booking of cluster) {
            let lane = lanes.findIndex((end) => end <= toMinutes(booking.starts_at));
            if (lane === -1) {
                lane = lanes.length;
                lanes.push(0);
            }
            lanes[lane] = toMinutes(booking.ends_at);
            placed.push({ ...booking, lane, lanes: 0 });
        }
        const count = lanes.length;
        for (let i = placed.length - cluster.length; i < placed.length; i += 1) {
            placed[i].lanes = count;
        }
        cluster = [];
        clusterEnd = -Infinity;
    };

    for (const booking of sorted) {
        if (cluster.length > 0 && toMinutes(booking.starts_at) >= clusterEnd) {
            flush();
        }
        cluster.push(booking);
        clusterEnd = Math.max(clusterEnd, toMinutes(booking.ends_at));
    }
    if (cluster.length > 0) flush();

    return placed;
}
```

El render posiciona cada bloque con `top = (inicio − 540) × 1.1`, `height = duration × 1.1`, `width = 100 / lanes %` y `left = lane × width`. Las horas de la grilla van de 09:00 a 18:00, una línea cada 66px.

- [ ] **Step 6: Reescribir `Dashboard/Index.jsx`**

Estructura de §8.4 y `.design/Dashboard.dc.html`: `PageHeader` → fila de cifras (una dominante más tres subordinadas separadas por filetes verticales, **sin tarjetas**) → dos columnas con el riel y la cola de atención más atajos.

Con el dataset sembrado la cola de atención muestra su `EmptyState`: **no es un caso degradado**, es el estado normal hasta que un visitante reserva un servicio con seña.

- [ ] **Step 7: Verificar los carriles en navegador**

Crear a mano dos reservas simultáneas de empleados distintos:

```bash
docker compose exec -T laravel.test php artisan tinker
```

Luego abrir `/dashboard` y comprobar que **ninguna tapa a la otra**, que la posición vertical sigue derivando de `starts_at` y que con el dataset sembrado —sin solapes— el riel se ve idéntico al artboard.

- [ ] **Step 8: Suite, Pint y commit**

```bash
docker compose exec -T laravel.test php artisan test
docker compose exec -T laravel.test vendor/bin/pint --test
git add app/Http/Controllers/DashboardController.php tests/Feature/Dashboard/DashboardMetricsTest.php resources/js/Components/domain/DayRail.jsx resources/js/Pages/Dashboard/Index.jsx
git commit -m "feat: build the operational dashboard on real queries"
```

---

## Task 13: Lista de reservas

**Files:**
- Modify: `app/Http/Controllers/Dashboard/BookingController.php`
- Modify: `tests/Feature/Dashboard/BookingsTest.php`
- Modify: `resources/js/Pages/Dashboard/Bookings/Index.jsx`

**Interfaces:**
- Consumes: `BookingStatusBadge`, `BookingActions`, `SlotPicker`, `Modal`, `Toast`, `TableShell`, `EmptyState`.
- Produces: filtros por query string `status`, `employee_id`, `from`.

- [ ] **Step 1: Tests de los filtros**

```php
    public function test_it_filters_by_status(): void { /* … */ }
    public function test_it_filters_by_employee(): void { /* … */ }
    public function test_it_filters_from_a_date(): void { /* … */ }
    public function test_an_employee_id_from_another_business_returns_nothing(): void { /* … */ }
    public function test_without_filters_it_returns_every_booking_of_the_business(): void { /* … */ }
```

- [ ] **Step 2: Correr y verificar que fallan**

```bash
docker compose exec -T laravel.test php artisan test --filter=BookingsTest
```

- [ ] **Step 3: Aplicar los filtros en el controlador**

Orden a `starts_at` **ascendente**, desempatando por `ends_at` y después por `id`. `businessId` se conserva tal cual: `BookingsRealtime` depende de él.

Sin paginación: decisión deliberada a escala de demo, mismo criterio que la Fase 10.5.

- [ ] **Step 4: Tests en verde**

- [ ] **Step 5: Reescribir la página**

Estructura de §8.5 y `.design/Main.dc.html`. Requisitos que no se pueden perder:

- **`BookingsRealtime` se conserva con su contrato intacto**, incluido el guard por `VITE_REVERB_APP_KEY` y `RELOAD_ONLY`.
- Agrupación por día en React: hoy, futuras ascendentes, pasadas descendentes al final. Micro-etiqueta en versalitas por grupo.
- Los `confirm()` nativos se reemplazan por `ConfirmDialog`.
- Reprogramar sale de la celda de tabla y pasa a `Modal` con `SlotPicker`, alimentado por el endpoint `reschedule-slots` existente.
- Vacío sin filtros y vacío con filtros usan **mensajes distintos**.
- Tras una recarga disparada por `booking.changed`, `Toast` con "Las reservas se actualizaron". **Sin badge "EN VIVO", sin indicador de conexión, sin terminología de Reverb.**

- [ ] **Step 6: Verificar el tiempo real**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose ps reverb
```

Con dos navegadores: staff en `/dashboard/bookings` y cliente reservando. La fila nueva tiene que aparecer sola y el toast asomar una vez.

- [ ] **Step 7: Suite y commit**

```bash
docker compose exec -T laravel.test php artisan test
git add app/Http/Controllers/Dashboard/BookingController.php tests/Feature/Dashboard/BookingsTest.php resources/js/Pages/Dashboard/Bookings/Index.jsx
git commit -m "feat: redesign the bookings list with server-side filters"
```

---

## Task 14: Detalle y alta de reserva

**Files:**
- Modify: `app/Http/Controllers/Dashboard/BookingController.php` (`create`)
- Modify: `tests/Feature/Dashboard/BookingsTest.php`
- Modify: `resources/js/Pages/Dashboard/Bookings/Show.jsx`
- Modify: `resources/js/Pages/Dashboard/Bookings/Form.jsx`

- [ ] **Step 1: Test del filtro de empleados**

```php
    public function test_the_form_only_offers_employees_assigned_to_the_service(): void
    {
        // Sin esto, CreateBooking:45 rechaza la combinación recién al guardar
        // con "Ese empleado no realiza este servicio".
    }
```

- [ ] **Step 2: Correr, verificar que falla, corregir `create()`**

Restringir la lista de empleados a los asignados al servicio elegido, igual que `Public\BookingController::employeesFor`.

- [ ] **Step 3: Reescribir `Show`**

Jerarquía de §8.6: estado y hora arriba → servicio, cliente, empleado → seña y pagos con `PaymentStatusBadge` → **acciones de ciclo de vida** (hoy solo existen en Index) → historial.

**Prohibido** exponer internals del proveedor: ni `external_id`, ni snapshots, ni eventos de webhook.

- [ ] **Step 4: Reescribir `Form`**

`SlotPicker` en lugar de `<select>`, resumen lateral con duración, precio y seña del servicio elegido, y las recargas parciales `only: ['slots']` que ya existen.

- [ ] **Step 5: Compilar, revisar, suite y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=BookingsTest
git add app/Http/Controllers/Dashboard/BookingController.php resources/js/Pages/Dashboard/Bookings tests/Feature/Dashboard/BookingsTest.php
git commit -m "feat: redesign booking detail and creation"
```

---

## Task 15: Servicios

**Files:**
- Modify: `resources/js/Pages/Dashboard/Services/Index.jsx`
- Modify: `resources/js/Pages/Dashboard/Services/Form.jsx`

- [ ] **Step 1: Ampliar la tabla**

Columnas de §8.8: nombre y descripción truncada, duración, buffer, precio, **seña**, estado, acciones. Los tres últimos datos ya vienen en la consulta actual y hoy se descartan.

Moneda formateada con `business.currency`, no `$` fijo.

- [ ] **Step 2: Rehacer el formulario**

Tres bloques —identidad, tiempo, dinero— más estado. **Todas** las etiquetas asociadas por `htmlFor`/`id`: hoy no lo están. El campo de seña explica su efecto.

- [ ] **Step 3: Compilar, revisar a tres anchos, suite y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=Service
git add resources/js/Pages/Dashboard/Services
git commit -m "feat: surface deposit, buffer and active state in the services area"
```

---

## Task 16: Personal y horarios

**Files:**
- Modify: `resources/js/Pages/Dashboard/Employees/Index.jsx`
- Modify: `resources/js/Pages/Dashboard/Employees/Schedule.jsx`
- Create: `resources/js/Components/domain/ScheduleEditor.jsx`

- [ ] **Step 1: Rehacer `Employees/Index`**

Tres secciones con encabezado, no tres tablas crudas. Servicios asignados pasan de checkboxes en línea a `Modal`. `future_bookings_count` pasa de `<p>` suelto a `Alert`. Activar/desactivar por `ConfirmDialog`.

- [ ] **Step 2: Escribir `ScheduleEditor`**

Vista semanal: siete filas de día con sus franjas y sus pausas anidadas. Reemplaza las cuatro tablas y tres formularios actuales. Agregar franja en línea por día. Licencias en sección aparte con fechas formateadas en la zona del negocio — hoy se muestran los timestamps crudos.

- [ ] **Step 3: Compilar, revisar, suite y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=Schedule
docker compose exec -T laravel.test php artisan test --filter=Employee
git add resources/js/Pages/Dashboard/Employees resources/js/Components/domain/ScheduleEditor.jsx
git commit -m "feat: redesign the staff and schedule areas"
```

---

## Task 17: Feriados, ajustes y cuenta

**Files:**
- Modify: `resources/js/Pages/Dashboard/Holidays/Index.jsx`
- Modify: `resources/js/Pages/Dashboard/Settings/Edit.jsx`
- Modify: `resources/js/Pages/Account/Edit.jsx`

- [ ] **Step 1: Rehacer `Holidays/Index`**

La vista previa de conflictos es la joya de esta pantalla: hoy `errors.bookings_preview` se renderiza como `<ul>` crudo y pasa a `Alert` con las hasta 5 reservas en conflicto y la explicación de que hay que cancelarlas o reprogramarlas antes.

- [ ] **Step 2: Rehacer `Settings/Edit` y corregir el defecto de copy**

Secciones: identidad, operación, reservas. **Corregir `resources/js/Pages/Dashboard/Settings/Edit.jsx:94`**: dice `/businesses/{business.slug}` y la ruta real es `/negocios/{business.slug}`.

Flash `status` pasa a `Toast`.

- [ ] **Step 3: Rehacer `Account/Edit`**

Se mantiene la elección de layout por rol, que ya es correcta. Se renderiza `email_verified_at` —hoy la prop llega y se descarta— como verificado con fecha o `Alert` con acción de reenvío.

- [ ] **Step 4: Verificar la corrección de la ruta**

```bash
docker compose exec -T laravel.test bash -lc "grep -c 'businesses/' resources/js/Pages/Dashboard/Settings/Edit.jsx"
```

Esperado: `0`.

- [ ] **Step 5: Compilar, suite y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=Holiday
docker compose exec -T laravel.test php artisan test --filter=BusinessSettings
docker compose exec -T laravel.test php artisan test --filter=Account
git add resources/js/Pages/Dashboard/Holidays resources/js/Pages/Dashboard/Settings resources/js/Pages/Account
git commit -m "feat: redesign holidays, business settings and account"
```

---

## Task 18: Descubrimiento público

**Files:**
- Modify: `app/Http/Controllers/Public/BusinessController.php`
- Modify: `tests/Feature/Public/BusinessIndexTest.php`
- Modify: `resources/js/Pages/Public/Business/Index.jsx`
- Modify: `resources/js/Pages/Public/Business/Show.jsx`
- Create: `resources/js/Components/domain/ServiceCard.jsx`

- [ ] **Step 1: Tests del puente**

```php
    public function test_the_listing_exposes_the_active_service_count_and_lowest_price(): void { /* … */ }
    public function test_inactive_services_are_excluded_from_the_count(): void { /* … */ }
    public function test_an_inactive_business_is_still_absent(): void { /* invariante de la Fase 10.5 */ }
```

- [ ] **Step 2: Correr, verificar que fallan, ampliar el controlador**

`index` agrega cantidad de servicios activos y precio mínimo con `withCount` y `min` — **sin N+1**. `show` agrega `price` y `deposit_amount` a la proyección de servicios.

- [ ] **Step 3: Rehacer las dos páginas**

`Index`: tarjetas con nombre, cantidad de servicios y "desde $X". **Prohibido** puntuaciones, reseñas, distintivos de popularidad o estrellas: no hay datos que los respalden.

`Show`: `ServiceCard` con nombre, descripción, duración, precio en la moneda del negocio y, **cuando el servicio pide seña, el importe de la seña** — hoy no se anuncia en ninguna parte.

- [ ] **Step 4: Compilar, suite y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=BusinessIndex
git add app/Http/Controllers/Public/BusinessController.php tests/Feature/Public/BusinessIndexTest.php resources/js/Pages/Public/Business resources/js/Components/domain/ServiceCard.jsx
git commit -m "feat: give the public business listing something to choose between"
```

---

## Task 19: Flujo de reserva pública

**Files:**
- Modify: `app/Http/Controllers/Public/BookingController.php`
- Modify: `tests/Feature/Public/BookingTest.php`
- Modify: `resources/js/Pages/Public/Business/Book.jsx`

**Es el flujo insignia.** Es la pantalla que más tiempo merece.

- [ ] **Step 1: Test del puente de la seña**

```php
    public function test_the_booking_page_announces_the_deposit_before_booking(): void
    {
        // Cierra la brecha C más seria: hoy el cliente descubre la seña
        // DESPUÉS de crear la reserva.
    }
```

- [ ] **Step 2: Correr, verificar que falla, ampliar `create()`**

Agregar `price`, `deposit_amount` y `config('payments.window_minutes')` a la proyección de servicios.

- [ ] **Step 3: Reescribir `Book.jsx`**

Estructura de §8.17 y `.design/PublicBook.dc.html`:

- Cuatro pasos en secuencia real. Los resueltos se colapsan a una línea con `Cambiar`; **el activo se marca con borde de tinta, no con spine de color** — no hay estado que comunicar.
- Tira de siete días; los días sin horario se muestran deshabilitados con explicación.
- `SlotPicker` con chips de 44px, no un `<select>`.
- **Resumen con precio, seña a pagar ahora y resto en el local**, más el bloque ámbar que explica que el turno queda pendiente y hay 30 minutos para pagar.
- Bajo el CTA: ventana de cancelación y una línea sobre el buzón compartido.
- **La disponibilidad la calcula Laravel.** Se conservan `only: ['employees']` y `only: ['slots']`.

- [ ] **Step 4: Revisión móvil obligatoria**

A 390px: pasos apilados, tira de fechas con desplazamiento horizontal, `SlotPicker` a 3 por fila con chips de 48px, resumen colapsado en **barra de acción fija al pie** con hora, seña y CTA. **Sin desplazamiento horizontal de página.**

- [ ] **Step 5: Compilar, suite y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
docker compose exec -T laravel.test php artisan test --filter=BookingTest
git add app/Http/Controllers/Public/BookingController.php tests/Feature/Public/BookingTest.php resources/js/Pages/Public/Business/Book.jsx
git commit -m "feat: rebuild the public booking flow and disclose the deposit up front"
```

---

## Task 20: Mis reservas y checkout simulado

**Files:**
- Modify: `app/Http/Controllers/Public/MyBookingsController.php`
- Modify: `tests/Feature/Public/MyBookingsTest.php`
- Modify: `resources/js/Pages/Public/MyBookings/Index.jsx`
- Modify: `resources/js/Pages/Demo/Checkout.jsx`

- [ ] **Step 1: Tests de los booleanos de permiso**

```php
    public function test_it_exposes_whether_the_customer_may_still_cancel(): void { /* dentro de la ventana */ }
    public function test_it_exposes_false_once_the_window_has_closed(): void { /* fuera de la ventana */ }
```

- [ ] **Step 2: Correr, verificar que fallan, agregar `can_cancel` y `can_reschedule`**

Derivados de `BookingPolicy`. Elimina la reimplementación en JavaScript del corte de cancelación que hoy vive en la página.

- [ ] **Step 3: Rehacer `MyBookings/Index`**

Agrupación: próximas, pendientes de seña, pasadas, canceladas. `PaymentStatusBadge` con importe. Se conservan `Pagar seña` y `Continuar el pago`.

**Sin tiempo real:** la Fase 10 acotó el broadcast a staff a propósito y esta fase **no** lo amplía.

- [ ] **Step 4: Rehacer `Demo/Checkout`**

**Toda la arquitectura de la Fase 9 se preserva**: rutas firmadas, `outcome_url` temporal, clamp de expiración y entrega en proceso del webhook no se tocan.

Divulgación reforzada de §8.19. Tres resultados con su consecuencia escrita al lado; el icono porta el color semántico, los botones no son bloques de color.

**Prohibido** campo de tarjeta, CVV, vencimiento, cuenta bancaria o imitación de cualquier pasarela real.

- [ ] **Step 5: Smoke del pago simulado**

Como cliente: reservar Coloración, pagar la seña, aprobar. La reserva tiene que pasar a confirmada y la pantalla de staff actualizarse sola. Después repetir con "Abandonar" y esperar el vencimiento.

- [ ] **Step 6: Suite y commit**

```bash
docker compose exec -T laravel.test php artisan test
git add app/Http/Controllers/Public/MyBookingsController.php tests/Feature/Public/MyBookingsTest.php resources/js/Pages/Public/MyBookings resources/js/Pages/Demo/Checkout.jsx
git commit -m "feat: redesign customer bookings and the simulated checkout"
```

---

## Task 21: Pasada de responsive y accesibilidad

**Files:**
- Modify: las páginas que la revisión encuentre rotas.

- [ ] **Step 1: Recorrer las quince pantallas a los tres anchos**

Home · `/como-funciona` · Login · Registro · Panel · Reservas · Detalle · Servicios · Personal · Horario · Feriados · Configuración · Negocios · Negocio · Reservar · Mis reservas · Checkout.

A **~1440**, **~1024** y **~390**, comparando contra los artboards.

- [ ] **Step 2: Buscar desplazamiento horizontal**

En cada pantalla y ancho, comprobar en DevTools que `document.documentElement.scrollWidth <= window.innerWidth`.

- [ ] **Step 3: Recorrer con teclado**

Tab por cada pantalla: foco siempre visible, orden lógico, sin trampas fuera de diálogos. En cada diálogo: `Escape` cierra, el foco vuelve al disparador, y en los destructivos **el foco arranca en Cancelar**.

- [ ] **Step 4: Verificar contraste en navegador**

El par más ajustado del sistema es `--muted` sobre `--bg`: **4,79:1**. Confirmarlo con la herramienta de contraste de DevTools, no solo por cálculo.

- [ ] **Step 5: Verificar `prefers-reduced-motion`**

Con la preferencia activada, drawer y diálogos no animan.

- [ ] **Step 6: Corregir lo que aparezca y commit**

```bash
docker compose exec -T laravel.test bash -lc "pnpm build"
git add -A resources/js
git commit -m "fix: responsive and accessibility findings from the cross-app pass"
```

---

## Task 22: Documentación y verificación final

**Files:**
- Modify: `docs/DEPLOYMENT_HANDOFF.md`
- Modify: `01-reservahub.md`

- [ ] **Step 1: Reescribir las secciones del handoff**

Los cambios obligatorios de §15 de la spec:

- **§3** deja de exigir SMTP real: en el despliegue público de portfolio, Mailpit es el destino SMTP previsto y su interfaz es superficie de producto.
- **§4** documenta `VITE_DEMO_MAIL_URL` como **pública, no secreta**, compilada en el bundle, con la misma nota de "exige `pnpm build`" que las `VITE_REVERB_*`.
- **§9** saca Mailpit de *Qué no debe exponerse nunca* — hoy las líneas 59 y 190 dicen que no debe desplegarse, lo que contradice el modelo aprobado.
- **§10** incorpora el buzón público, el reinicio diario, las limitaciones aceptadas de §7.5 —incluida la del restablecimiento de contraseña de `owner@reservahub.test`— y el acoplamiento del horario del contador.
- **Sección nueva** de contrato del reinicio diario: 00:00 `America/Argentina/Buenos_Aires`, `db:seed --class=DemoSeeder` y **nunca** `DatabaseSeeder`, vaciado del buzón, y el candidato `php artisan demo:reset` con guarda como trabajo de Fase 12.

- [ ] **Step 2: Actualizar la tabla de estado del roadmap**

Fase 11 pasa a Hecha. Anotar que la expansión del `DemoSeeder` con clientes y reservas la cerró la Fase 11 y ya no es pendiente de la Fase 12.

- [ ] **Step 3: Verificación técnica completa**

```bash
docker compose exec -T laravel.test php artisan test
docker compose exec -T laravel.test vendor/bin/pint --test
docker compose exec -T laravel.test bash -lc "pnpm build"
```

Esperado: suite entera en verde, Pint limpio, build OK.

- [ ] **Step 4: Smoke de staff**

Login como `owner@reservahub.test`, recorrer panel, reservas, servicios, personal, horario, feriados y configuración.

- [ ] **Step 5: Smoke de cliente**

Desde incógnito: `/negocios` → negocio → reservar → checkout → mis reservas.

- [ ] **Step 6: Smoke de tiempo real de la Fase 10**

Con dos navegadores, seguir el guion completo de `CLAUDE.md`, incluido el aislamiento entre negocios: un tercer navegador con staff de **otro** negocio no debe recibir ningún frame `booking.changed` del primero.

- [ ] **Step 7: Verificación de datos reales**

```bash
docker compose exec -T laravel.test bash -lc "grep -rnE 'const (bookingsToday|growth|revenue)' resources/js || echo 'sin datos falsos'"
```

Recorrer cada cifra visible del panel y confirmar que tiene consulta detrás.

- [ ] **Step 8: Commit final**

```bash
git add docs/DEPLOYMENT_HANDOFF.md 01-reservahub.md
git commit -m "docs: bring the deployment handoff in line with the public demo model"
```

---

## Autorevisión del plan

**Cobertura de la spec.** Cada sección tiene tarea: §5 → Tasks 2–5 · §6.1 → Task 7 · §6.2 → Task 8 · §6.3 → Task 11 · §7.3 → Task 9 · §7.4 → Task 11 · §8.1 → Task 10 · §8.2 → Task 11 · §8.3 → Task 8 · §8.4 → Task 12 · §8.5 → Task 13 · §8.6–8.7 → Task 14 · §8.8–8.9 → Task 15 · §8.10–8.11 → Task 16 · §8.12–8.14 → Task 17 · §8.15–8.16 → Task 18 · §8.17 → Task 19 · §8.18–8.19 → Task 20 · §8.20 → Task 8 · §9 → Tasks 7, 10, 11, 12, 13, 14, 18, 19, 20 · §10 → Task 6 · §11 → distribuido en cada tarea de página · §12–13 → Task 21 · §15 → Task 22 · §16.1 → Task 1 · §16.2 → Task 17 · §16.3 → Task 14 · §16.4 → Task 20 · §17 → Task 22.

**Consistencia de tipos.** `BOOKING_SPINE` se exporta en Task 5 y se consume en Tasks 12 y 13. `assignLanes` se define en Task 12 y no la usa nadie más. `FormField` recibe `children` como función en Task 3 y así se consume en las Tasks 8, 14, 15, 16 y 17. `DemoResetCountdown` no recibe props obligatorias y se monta igual en Tasks 10 y 11. `timeline` tiene la misma forma en Task 10 (productor) que en `Home.jsx` (consumidor).

**Orden de dependencias.** Task 1 desbloquea la puerta de suite verde de todas las demás. Tasks 2–5 producen las primitivas antes de que cualquier página las consuma. Task 6 produce el dataset que las Tasks 10, 12, 13, 18, 19 y 20 necesitan para verificar en navegador. Ninguna tarea consume algo que se defina después.

**Sin marcadores de posición.** Los pasos con código llevan código real. Los pasos de página remiten al artboard aprobado y a la sección de la spec que fija su anatomía, que es la autoridad de diseño, y enumeran los requisitos que no se pueden perder.
