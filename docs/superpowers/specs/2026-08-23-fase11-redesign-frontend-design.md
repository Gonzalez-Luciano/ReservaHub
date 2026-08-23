# Fase 11 — Rediseño frontend y experiencia de demo

Fecha: 2026-08-23
Rama: `feat/phase-11-frontend-redesign`
Estado: diseño aprobado, pendiente de plan de implementación

Conceptos visuales aprobados: canvas de 8 artboards publicado como Artifact
(Reservas, Panel, Home, `/como-funciona`, Reserva pública, Registro, Checkout
simulado, Reserva móvil 390). Los artboards son autoridad de diseño junto con
este documento; ante discrepancia, **manda este documento**.

---

## 1. Objetivo y frontera

Convertir una aplicación funcional con frontend mínimo en una demo SaaS
profesional, coherente y demostrable, sin rediseñar el backend por motivos
visuales.

La Fase 11 es frontend-led. Puede agregar cambios chicos de Laravel cuando una
pantalla aprobada los necesita (una prop nueva, una consulta acotada, un filtro),
siempre a través de las Actions y Policies existentes y siempre con test de
backend. **No** construye dominios nuevos.

### Invariantes que no se tocan

1. **Fase 9 — pagos.** `ApplyPaymentResult` sigue siendo el único camino que
   aplica un resultado del proveedor; `ConfirmBooking` el único que confirma;
   `ProcessPaymentWebhook` el único borde de procesamiento. El frontend no muta
   `Payment` ni `Booking`, no saltea las rutas firmadas del checkout simulado y
   no toca el estado del proveedor simulado. El orden de bloqueo
   `webhook_events → bookings → payments` no se altera.
2. **Fase 10 — tiempo real.** `BookingChanged` sigue siendo el único evento de
   broadcast. El payload sigue siendo `{booking_id, change, updated_at}`: una
   pista de invalidación, no datos. `BookingsRealtime` sigue suscribiéndose a
   `private-business.{businessId}` y respondiendo con
   `router.reload({ only: ['bookings'] })`. No se agrega store de tiempo real,
   ni suscripción de clientes, ni canales nuevos, ni datos de reserva sobre el
   socket.
3. **Multi-tenancy.** Toda consulta sigue filtrada por `business_id`. Las
   Policies siguen siendo la autoridad; ocultar algo en el frontend nunca es el
   control de acceso.
4. **Arquitectura.** Laravel + Inertia + React + Tailwind 4. Sin Next.js, sin
   segunda SPA, sin SSR, sin Redux/Zustand/MobX, sin React Query.

---

## 2. Verificación de la Fase 10

Verificada contra el repositorio antes de diseñar. **Sin bloqueos.**

| Comprobación | Evidencia |
|---|---|
| Reverb instalado y en runtime | `laravel/reverb`; servicio `reverb` en `compose.yaml:72-91` |
| Un solo evento de broadcast | `app/Events/Broadcasting/` contiene exactamente `BookingChanged.php` |
| `ShouldDispatchAfterCommit` | Presente en `BookingChanged` |
| Payload = pista, no datos | `broadcastWith()` devuelve `{booking_id, change, updated_at}`; `businessId` enruta y no viaja |
| Autorización de canal | `routes/channels.php`: rol de staff + `is_active` + negocio propio + negocio activo, comparado como string |
| Cliente | `resources/js/Components/BookingsRealtime.jsx`: `useEcho` → coalesce 250 ms → `router.reload` |
| Cableado | `Dashboard/Bookings/Index.jsx` recibe `businessId` de `BookingController::index()`; guarda por `VITE_REVERB_APP_KEY` |
| Tests | `tests/Feature/Realtime/{BroadcastBookingChange,ChannelAuthorization,PaymentRealtimeIntegration}Test.php`, `ReverbConfigTest` |

---

## 3. Línea base verificada

Corrige la cifra del roadmap: **24 archivos de página**, no 17 (el 17 precede a
las Fases 8, 9 y 10.5). **5 componentes compartidos**, no 4.

- `resources/css/app.css`: 9 líneas, solo declara `--font-sans`.
- Fuente: `Instrument Sans`, auto-hospedada por `laravel-vite-plugin/fonts`
  (proveedor bunny). Sin CDN, sin problema de CSP, portable al despliegue.
  Confirmado: el build emite `public/build/assets/fonts-*.css`.
- Bundle actual: `app-*.js` 446,79 kB (129,51 kB gzip), `app-*.css` 18,34 kB.
- Suite: **537 pasan, 5 fallan** (ver §16.1). Idéntico en el checkout principal y
  en el worktree.

### 3.1 Nombres de componente Inertia congelados

29 aserciones de test referencian nombres de componente. **Ninguno cambia de
nombre en esta fase.** Bloqueados como mínimo:

`Public/Business/Index` · `Public/Business/Show` · `Public/MyBookings/Index` ·
`Dashboard/Bookings/Index` · `Dashboard/Bookings/Form` · `Dashboard/Settings/Edit` ·
`Dashboard/Holidays/Index` · `Account/Edit` · `Invitations/Unavailable`

---

## 4. Auditoría

### 4.1 Matriz de páginas

| Página | Rol | Acción | Motivo |
|---|---|---|---|
| `Home` | público | **Rediseñar** | 15 líneas; sin explicación de producto ni aviso de demo |
| `Auth/Login` | invitado | Rediseñar | familia auth compartida |
| `Auth/Register` | invitado | **Rediseñar + aviso** | único lugar donde se tipea una contraseña |
| `Auth/ForgotPassword` | invitado | Rediseñar | familia auth |
| `Auth/ResetPassword` | invitado | Rediseñar | familia auth |
| `Auth/VerifyEmail` | autenticado | Rediseñar | familia auth |
| `Dashboard/Index` | staff | **Construir** | hoy es un placeholder que lo admite |
| `Dashboard/Bookings/Index` | staff | **Rediseñar** | pantalla operativa central |
| `Dashboard/Bookings/Show` | staff | **Rediseñar + acciones** | volcado de campos; sin acciones de ciclo de vida |
| `Dashboard/Bookings/Form` | staff | Rediseñar + corregir | lista empleados no asignados al servicio |
| `Dashboard/Employees/Index` | staff | Rediseñar | tres tablas apiladas |
| `Dashboard/Employees/Schedule` | staff | Rediseñar | cuatro tablas y tres formularios |
| `Dashboard/Services/Index` | staff | **Rediseñar + ampliar** | oculta seña, buffer y estado activo |
| `Dashboard/Services/Form` | manager | Rediseñar | etiquetas sin `htmlFor` |
| `Dashboard/Holidays/Index` | manager | Rediseñar | la vista previa de conflictos merece presentación |
| `Dashboard/Settings/Edit` | manager | Rediseñar + **corregir bug** | ver §16.2 |
| `Account/Edit` | cualquiera | Rediseñar | `email_verified_at` llega y no se muestra |
| `Public/Business/Index` | público | **Rediseñar + ampliar** | solo `id, name, slug`: nada para elegir |
| `Public/Business/Show` | público | Rediseñar | `$` fijo ignora la moneda; no revela seña |
| `Public/Business/Book` | cliente | **Rediseñar** | flujo insignia; hoy cuatro `<select>` |
| `Public/MyBookings/Index` | cliente | Rediseñar | duplica el corte de cancelación en JS |
| `Demo/Checkout` | firmado | Rediseñar | ya es honesto; necesita el sistema |
| `Invitations/Accept` | invitado | Heredado | hereda la familia auth |
| `Invitations/Unavailable` | invitado | Heredado | hereda la familia auth |
| **`ComoFunciona`** | público | **Nueva** | guía de la demo compartida (§7) |

Ninguna página se elimina. Ninguna se difiere.

### 4.2 Capacidades de backend

**A — expuestas y claras.** CRUD de servicios; CRUD de horarios, pausas y
licencias; alta/baja de feriados con vista previa de conflictos; invitaciones de
personal; las cinco transiciones de reserva; reprogramación con slots en vivo;
ajustes del negocio; perfil y contraseña; checkout simulado; tiempo real de staff.

**B — expuestas, mal presentadas.** Estado de pago (enum crudo en
`Bookings/Show`); activar/desactivar empleado (un subrayado suelto); vista previa
de feriados en conflicto; disponibilidad (un `<select>`); historial de estados;
multi-tenancy (invisible).

**C — backend existe, falta UI útil.** Métricas del panel; `deposit_amount`,
`buffer_minutes` e `is_active` ausentes de `Services/Index`; descripción y
cantidad de servicios ausentes del descubrimiento público; `email_verified_at`
sin renderizar; **la seña nunca se anuncia antes de reservar** — el cliente se
entera después de crear la reserva.

**D — permanecen invisibles.** `webhook_events`, `simulated_provider_payments`,
`payments:reconcile`, `bookings:expire-unpaid`, `booking_reminders`, tokens de
Sanctum, `businesses.logo_path` (sin uso a propósito).

---

## 5. Sistema de diseño

### 5.1 Paleta canónica

Única paleta válida. Sustituye cualquier valor anterior.

| Token | Hex | Uso |
|---|---|---|
| `--bg` | `#ECEDE8` | fondo de página; papel gris-oliva |
| `--surface` | `#FFFFFF` | tarjetas, filas, superficies elevadas |
| `--border` | `#D9DAD3` | hairline 1px; **el** recurso de separación |
| `--muted` | `#676860` | metadatos, encabezados de tabla, texto secundario |
| `--fg` | `#191A17` | tinta: texto, botón primario, wordmark |
| `--chrome` | `#E3E5DE` | fondo de la barra lateral del panel |
| `--chrome-active` | `#D9DAD3` | ítem activo de navegación (mismo valor que `--border`) |

**Regla fundacional: la marca no tiene color.** Todo el rango cromático queda
reservado para significado. No se introduce hue de acento.

`#ECEDE8` reemplaza deliberadamente a `#F2F1EE`: el anterior era
indistinguible del crema por defecto (`#F4F1EA`). Este tiene dominante
verde-gris. Cambio intencional, presente en los ocho artboards aprobados.

### 5.1.1 Escala de apoyo

Valores presentes en los artboards aprobados que no son tokens de marca pero sí
son canónicos. Se declaran para que la implementación no los invente.

| Token | Hex | Uso |
|---|---|---|
| `--fg-body` | `#3A3835` | cuerpo largo en superficies públicas; más suave que la tinta |
| `--fg-placeholder` | `#A8A29E` | texto de marcador en campos y días deshabilitados |
| `--fg-on-ink-muted` | `#B8B5AE` | texto secundario sobre superficie tinta |
| `--rule-faint` | `#EDEEE9` | líneas de la grilla horaria del riel del día |
| `--surface-inset` | `#FAFAF8` | superficies embebidas (bloque de credenciales de demo) |
| `--surface-disabled` | `#FAFAF9` | celdas de día deshabilitadas; bloque neutro del riel |
| `--slot-taken` | `#E1E3DB` | bloque ocupado de la tira del día del Home |
| `--slot-free-border` | `#C3C5BB` | borde punteado del hueco libre de esa tira |
| `--check-on-ink` | `#86EFAC` | icono de check sobre el botón tinta de aprobar pago |

Las tintas de bloque del riel del día derivan del estado: `#F6FBF7` confirmada,
`#FFFBEB` pendiente, `#FAFAF9` cancelada. Son fondos de bloque, no fondos de
badge: mucho más claros, para que el texto del bloque siga siendo tinta.

### 5.2 Color semántico

| Estado de reserva | Texto | Fondo de badge | Icono | Refuerzo |
|---|---|---|---|---|
| Pendiente | `#92400E` | `#FEF3C7` | reloj | — |
| Confirmada | `#166534` | `#DCFCE7` | check | — |
| Completada | `#334155` | `#E2E8F0` | check en círculo | — |
| Cancelada | `#57534E` | `#F5F5F4` | cruz | **hora tachada** |
| Ausencia | `#991B1B` | `#FEE2E2` | círculo con barra | — |

**Superficies de aviso.** Solo el ámbar se instancia como superficie de aviso
(`#FFFBEB` con borde `#EBD8A8` y texto `#78350F`), porque es el único estado que
necesita explicar algo al usuario. Los otros cuatro existen únicamente como
badge, así que no llevan token de borde.

Pagos reutilizan ámbar / verde / rojo / piedra, **siempre prefijados con
"Seña"** para que el contexto los desambigüe.

Completada (frío) y Cancelada (cálido) se separan por temperatura, icono,
etiqueta y tachado. **Ningún estado depende solo del color.**

### 5.3 Foco y acción — sin hue

- Enlaces: tinta más subrayado con `text-underline-offset: 3px`.
- Botón primario: relleno tinta, texto papel.
- Botón secundario: superficie blanca, borde `--border`, texto tinta.
- Botón destructivo: contorno rojo, texto rojo, **nunca relleno**.
- **Anillo de foco:** 2px tinta más 2px de separación blanca
  (`outline: 2px solid var(--fg); outline-offset: 2px`). Visible sobre papel y
  sobre el botón tinta sin inventar un sexto color.

### 5.4 Tipografía

Una sola familia: `Instrument Sans`, ya auto-hospedada. **No se agrega una
segunda familia.** Se descarta explícitamente un mono para horarios: el roadmap
§11.7 prohíbe decoración de terminal/código.

| Rol | Tamaño/interlínea | Peso | Tracking |
|---|---|---|---|
| Display (hero) | 56/60 | 600 | −0.03em |
| Título de página pública | 44/48 | 600 | −0.032em |
| Título de página panel | 24/32 | 600 | −0.02em |
| Sección | 28/36 | 600 | −0.02em |
| Subsección | 19/26 | 600 | −0.015em |
| Cuerpo público | 17/28 · 16/26 | 400 | — |
| Cuerpo panel | 15/24 | 400 | — |
| Meta | 13/20 | 400 | — |
| Micro-etiqueta | 12/16 | 500 | **+0.08em, mayúsculas** |
| Cifra dominante | 68/64 | 600 | −0.05em |

`font-variant-numeric: tabular-nums` obligatorio en horas, duraciones, importes,
contadores y fechas numéricas.

**La micro-etiqueta en versalitas se reserva a metadatos operativos**: fechas de
agrupación, encabezados de tabla, etiquetas de dato. **No** se usa como kicker
decorativo sobre cada título de sección.

### 5.5 Superficie, borde, radio, sombra, espaciado

- **Separación = borde de 1px.** Las tarjetas **no** llevan sombra.
- **Sombras: exactamente dos**, ambas de overlay (diálogo/drawer, menú
  desplegable). Nada más en la aplicación proyecta sombra.
- **Radios:** 4px campos, botones, badges, chips. 6px superficies. **0 en el
  spine.**
- **Espaciado:** base 4px. Panel 8/12/16/24. Público 16/24/40/64.

### 5.6 Firma 1 — el spine de estado

Barra de 3px al borde izquierdo, canto vivo.

**Aparece solo en filas de lista y bloques de agenda**, donde funciona como
canaleta de estado dentro de una grilla densa: filas de `Bookings/Index`, filas
de la cola de atención del panel, bloques del riel del día.

**Nunca como borde de acento de una tarjeta suelta.** Esa forma —contenedor
redondeado con borde izquierdo de color— es un patrón reconocible de interfaz
generada por IA y queda prohibida en este sistema.

**Lleva color semántico solo si la entidad tiene estado aprobado** (reserva,
pago). Servicios, empleados, navegación, secciones y pasos de flujo **no**
reciben color de categoría decorativo: su spine, si existe, es neutro
(`--border`), y el paso activo de un flujo se marca con borde de tinta.

Los avisos son superficies tintadas con borde del mismo tono y el icono en línea
con la etiqueta — sin barra lateral.

### 5.7 Firma 2 — geometría veraz del tiempo

**Ventana canónica: 09:00–18:00.** Nueve horas, idéntica en el riel del panel,
en la tira del Home y en los horarios que siembra el `DemoSeeder`. Un solo
número, para que ninguna reserva sembrada quede recortada fuera del riel.

- **Panel:** riel vertical de 09:00 a 18:00 sobre una grilla horaria a
  **1,1 px por minuto** — 66px por hora, 594px de alto. La altura del bloque
  **es** la duración: una coloración de 90 min ocupa exactamente el triple que un
  corte de 30. El riel es de una sola columna, así que el dataset sembrado no
  contiene reservas superpuestas en el mismo día.
- **Home:** la misma idea en horizontal, sobre una pista de 1120px que cubre las
  mismas nueve horas (2,074 px/min): bloques ocupados y huecos libres de un día
  real.

Ninguna de las dos es decorativa: ambas se derivan de `starts_at`, `ends_at` y
`duration_minutes` reales entregados por el servidor (§8.1 y §9.1). **Ninguna
de las dos admite datos fabricados en el cliente.**

### 5.8 Iconografía

**Un solo tratamiento SVG en línea.** Grilla de 16, `stroke-width` 1.5–1.6,
`stroke-linecap`/`linejoin` redondeados, `fill="none"`, color por `currentColor`
salvo cuando el icono porta semántica propia.

Prohibido mezclar glifos Unicode, emoji y SVG. En particular, "completada"
**no** se implementa como dos checks de texto: usa un check dentro de círculo.

Tamaños: 13px en badges, 14px en línea con texto, 16px en navegación y botones,
18–20px en acciones destacadas.

### 5.9 Decisión de librería de componentes

**Tailwind 4 más primitivas propias. Cero dependencias nuevas de runtime.**

No se instala shadcn, Radix ni Headless UI. Los casos difíciles de
accesibilidad se resuelven con la plataforma: `<dialog>` nativo con
`showModal()` para modal, confirmación y drawer; menús con botón más lista y
foco móvil.

**El `<dialog>` nativo es la base, no el contrato completo.** Cada componente de
diálogo debe definir explícitamente:

1. nombre accesible vía `aria-labelledby` apuntando al título;
2. foco inicial deliberado — nunca el primer elemento por accidente;
3. cierre por `Escape` y por el evento `cancel`;
4. **restauración del foco al elemento disparador** al cerrar;
5. interacción de teclado completa (Tab cicla dentro, Enter/Space activan);
6. **en confirmaciones destructivas, el foco inicial va a la acción segura**,
   jamás a la destructiva.

### 5.10 Primitivas

**De sistema** (dos o más lugares aprobados las reutilizan):

`Button` · `IconButton` · `Input` · `Select` · `Textarea` · `FormField`
(envuelve el `InputError` existente) · `Surface` · `PageHeader` · `StatusBadge`
· `EmptyState` · `Alert` · `Toast` · `TableShell` · `StatCard` · `Modal` ·
`ConfirmDialog` · `Drawer`

**De dominio** (exentas de la regla de dos lugares cuando encapsulan una
interacción real del dominio):

`BookingStatusBadge` · `PaymentStatusBadge` · `BookingActions` · `ServiceCard` ·
`SlotPicker` · `ScheduleEditor` · `DemoResetCountdown`

**Preservado sin cambios de contrato:** `BookingsRealtime`.

No se crean primitivas fuera de esta lista.

### 5.11 Voz

Español rioplatense, voseo, tono directo y sin adornos. Etiquetas de acción en
infinitivo o imperativo según ya se usa (`Guardar`, `Confirmar`, `Reservar y
pagar la seña`), y una acción conserva su nombre en todo el flujo.

**Primera persona del singular, nunca del plural.** ReservaHub lo hizo una sola
persona y la copia tiene que reflejarlo: *"te pido que lo ignores"*, no *"te
pedimos"*. Nada de `nuestro`, `nuestra`, `hacemos`, `enviamos`, `ofrecemos` ni
plural mayestático — ese registro finge una empresa detrás de un proyecto de
portfolio.

La mayor parte de la copia ni siquiera necesita primera persona: preferir la
forma impersonal o dirigirse directamente a quien lee (*"los pagos son
simulados"*, *"usá datos ficticios"*). La primera persona del singular queda
para los dos lugares donde hay un pedido genuino a la persona que visita —
respetar los datos de otra prueba y no interferir con los correos ajenos— y para
el bloque de contacto del pie.

Los errores no piden disculpas ni son vagos: dicen qué pasó y cómo seguir. Un
estado vacío es una invitación a actuar, no un mensaje de ausencia.

---

## 6. Arquitectura de información

### 6.1 Shell de staff — barra lateral colapsable

Riel izquierdo de 240px: identidad del negocio arriba, navegación consciente del
rol en el medio, usuario abajo.

| Ancho | Comportamiento |
|---|---|
| ≥1280px | riel de 240px expandido |
| 1024–1279px | riel colapsable a 64px (solo iconos), estado elegido por la persona y recordado en `localStorage` |
| <768px | drawer fuera de lienzo detrás de un botón `≡`, con el contrato de `<dialog>` de §5.9 |

Navegación por rol:

| Ítem | owner | admin | employee |
|---|---|---|---|
| Panel | ✔ | ✔ | ✔ |
| Reservas | ✔ | ✔ | ✔ |
| Servicios | ✔ | ✔ | ✔ (solo lectura) |
| Personal | ✔ | ✔ | — |
| Feriados | ✔ | ✔ | — |
| Configuración | ✔ | ✔ | — |

El horario de un empleado se alcanza desde su fila en Personal: es por-empleado,
no es un destino de nivel superior. **Ocultar un ítem nunca sustituye a la
Policy**, que sigue rechazando el acceso directo por URL.

Pie del shell de staff: una línea de 12px dentro de la columna de contenido, bajo
un filete — aviso de demo, enlace a `/como-funciona` y `mailto` de contacto. Va
dentro del contenido y **no** cruza la barra lateral.

### 6.2 Shell público

Cabecera en toda página, con o sin sesión: wordmark `ReservaHub`, la micro-etiqueta
`DEMO PÚBLICA` en versalitas (texto, **no** pastilla decorativa), y a la derecha
`Negocios`, `Cómo funciona la demo` y, según sesión, `Ingresar` / `Crear cuenta`
o el menú de cuenta con `Mis reservas`.

Pie: bloque de contacto con dirección visible y `mailto`, enlaces de la demo, y
la línea `No es un servicio comercial en funcionamiento.`

Esto corrige un hueco real: hoy `PublicLayout` renderiza navegación **solo si hay
sesión**, así que un visitante en `/negocios` no tiene ni forma de volver al
inicio ni de iniciar sesión.

### 6.3 Rutas

| Ruta | Archivo | Nota |
|---|---|---|
| `GET /como-funciona` | **`routes/public.php`** | permanente; nombre `public.guide` |
| `GET /demo/pagos/{externalId}/checkout` | `routes/demo.php` | condicional, sin cambios |

`/como-funciona` va en `routes/public.php`, **no** en `routes/demo.php`, porque
ese archivo se carga solo cuando el proveedor ligado es el simulado
(`routes/web.php:18`) y la guía es producto permanente.

No se crea alias `/demo`: la ruta no existe todavía, no hay compatibilidad que
mantener.

---

## 7. Modelo de demo pública compartida

El despliegue público es un sandbox de portfolio **público, compartido,
descartable**. Varios visitantes sin relación pueden usarlo a la vez. Es
comportamiento esperado, no un fallo.

**No se construye aislamiento por visitante**: ni base por visitante, ni buzón
por visitante, ni filtrado por sesión, ni ruteo SMTP por visitante, ni visor de
correo propio, ni proxy de solo lectura.

### 7.1 Divulgación progresiva — cuatro niveles, sin repetición

1. **Chrome** — `DEMO PÚBLICA` en versalitas junto al wordmark y
   `Cómo funciona la demo` en la navegación. Presente siempre, cuesta cero
   atención.
2. **Home** — franja informativa de tres columnas bajo el hero: es compartida /
   se restaura con el contador / usá datos ficticios, más el enlace a la guía.
   **Deliberadamente neutra**, sin ámbar: es información, no un estado de
   advertencia.
3. **Puntos de entrada de datos** — una sola línea donde de verdad se escribe
   algo: Registro (ámbar, único lugar donde se tipea una contraseña) y resumen
   de reserva (neutra, avisa del buzón compartido).
4. **`/como-funciona`** — la explicación completa.

`Login` no lleva aviso: usa cuentas sembradas y no se ingresa información nueva
significativa. **No hay banner persistente en todas las páginas.**

### 7.2 Reinicio diario

Todos los días a las **00:00 `America/Argentina/Buenos_Aires`** el despliegue
público vuelve a estado sembrado conocido: se restauran los datos, se ejecuta
`php artisan db:seed --class=DemoSeeder` y se vacía el buzón.

**La Fase 11 no implementa nada de eso.** Es dueña de comunicarlo: el contador,
el copy y el requisito escrito en el handoff.

### 7.3 `DemoResetCountdown`

**Dos ubicaciones, ninguna más.** Home (compacto, dentro de la franja) y
`/como-funciona` (prominente, 40px). **No** va en `PublicLayout` ni en el panel:
sería chrome repetido.

**Cálculo — contrato exacto.** Sin librería de fechas.

1. `Intl.DateTimeFormat` con `timeZone: 'America/Argentina/Buenos_Aires'`,
   `hourCycle: 'h23'`, y `hour`, `minute`, `second` a dos dígitos.
2. **`formatToParts()`**, nunca `format()`: se extraen las partes por su `type`
   (`hour`, `minute`, `second`) y se convierten con `Number()`. Parsear una
   cadena formateada por locale es frágil — algunos locales representan la
   medianoche como `24:00`, y `hourCycle: 'h23'` más `formatToParts()` eliminan
   esa clase de error por completo.
3. `restantes = 86400 − (hora × 3600 + minuto × 60 + segundo)`.
4. **Minutos por exceso:** `Math.ceil(restantes / 60)`, y de ahí horas y
   minutos. Así la interfaz nunca muestra `0 h 0 min` mientras todavía faltan
   segundos para el reinicio.

Un visitante en cualquier país ve el mismo instante. **Sin sincronización con el
backend.**

**Presentación.** Precisión al minuto: `8 h 42 min`. **Nunca** segundos
animados. Tinta sobre papel, sin caja de color, sin rojo, sin pulso: no puede
leerse como contador de oferta. Numerales tabulares.

**Sin salto de layout.** El valor cambia de ancho a lo largo del día
(`12 h 42 min` → `9 h 5 min`). El `<span>` del valor lleva `min-width` fijo,
dimensionado para el caso más largo, además de `tabular-nums`. Sin esto la
franja del Home se reacomodaría sola cada 30 segundos.

**Actualización** cada 30 s con `setInterval`, cancelado en `useEffect` cleanup.

**Accesibilidad: sin `aria-live`.** Texto normal. No interrumpe a lectores de
pantalla cada minuto, no mueve el foco, no reordena nada.

**Al llegar a cero** rueda al día siguiente por cálculo natural. **No** recarga,
no desloguea, no borra estado, no redirige, no dispara nada. El contador es
informativo: significa "está programado para reiniciarse", no "el backend se
reinicia en este milisegundo".

El horario vive en una **constante de módulo**, no en variable de entorno: una
env var no elimina el acoplamiento con la programación real, solo lo reubica, y
hay un solo despliegue. Queda anotado en el handoff como punto de deriva.

### 7.4 Buzón público

Mailpit pasa a ser parte del producto de la demo. El CTA
`Ver los emails de la demo` vive en `/como-funciona`, precedido por el aviso de
que el buzón es compartido, que pueden aparecer mensajes de otras personas, que
hay que ignorar los ajenos y que no hay que usar una dirección real.

Se referencia con enlace —no repitiendo el CTA— desde el resumen de reserva, el
campo de email del registro y el cierre del Home.

**Contrato de configuración: `VITE_DEMO_MAIL_URL`.** Sigue la convención que
`.env.example:54-56` ya documenta: todo lo que empieza con `VITE_` se compila
dentro del bundle y **es público por definición**. No es un secreto y nunca debe
tratarse como tal. Cambiarla exige `pnpm build` y redesplegar `public/build`.

**Si la variable no está definida, el CTA no se renderiza.** Es el estado por
defecto en desarrollo local. Nada se rompe.

**ReservaHub no depende del buzón.** Si Mailpit está caído: reservas, panel,
disponibilidad, pagos simulados y tiempo real funcionan idénticos. Ninguna página
de Laravel lo consulta. **Sin health polling, sin verificación de disponibilidad,
sin estado de conexión en el frontend.** El CTA es un `<a target="_blank"
rel="noopener">` y nada más.

### 7.5 Limitaciones aceptadas — a registrar por escrito

- Un visitante puede borrar o marcar mensajes individuales del buzón. Aceptado.
- Los correos contienen enlaces accionables (verificación, restablecimiento,
  invitaciones) y otro visitante podría abrirlos. Aceptado.
- **Concretamente:** un visitante puede pedir restablecimiento de contraseña para
  `owner@reservahub.test`; el enlace llega al buzón público y cualquiera puede
  tomar la cuenta principal hasta el próximo reinicio. Aceptado: todo es
  descartable y el reinicio restaura la contraseña sembrada.

**No se rediseña** autenticación, restablecimiento, invitaciones ni verificación
para aislar esto. La frontera de seguridad es: todo es demo, se avisa, no se
cargan datos reales, contraseñas descartables, reinicio diario.

### 7.6 Topología registrada, no configurada

```
reservahub.lucianogonzalez.dev        →  ReservaHub
mail.reservahub.lucianogonzalez.dev   →  buzón de la demo
```

Hostname separado en lugar de una ruta bajo la aplicación: mantiene separados el
enrutamiento de Laravel, los assets y la API de Mailpit. Coherente con la
preferencia de *una sola frontera pública* que el handoff ya declara para Reverb.

La Fase 11 **no toca** DNS, Cloudflare, `cloudflared`, proxy inverso, puertos del
host, Compose de producción ni red Linux.

---

## 8. Especificación por página

Convención: **Props** lista solo lo que cambia respecto de lo actual.

### 8.1 `Home`

- **Propósito.** Que un visitante entienda en segundos qué es ReservaHub, que es
  una demo pública compartida, y por dónde empezar.
- **Layout.** Shell público. Hero a la izquierda (máx. 760px): micro-etiqueta
  `DEMO DE PORTFOLIO`, `h1` "Turnos que no se superponen.", párrafo de producto,
  dos acciones (`Ver negocios y reservar` primaria, `Entrar como negocio`
  secundaria), línea de cuentas de demostración. Debajo, a 1120px, la tira del
  día a escala. Debajo, la franja de demo de tres columnas. Luego "Dos lados, una
  sola aplicación" (dos tarjetas), "Lo que pasa por debajo" (cuatro ítems en dos
  columnas, sin cajas ni filetes, separados por espacio) y un párrafo de cierre
  sobre roles y correo. Pie con contacto.
- **Acción primaria.** `Ver negocios y reservar` → `/negocios`.
- **Estados.** Si el servidor no devuelve agenda (`timeline === null`), **la tira
  del día no se renderiza**: el hero conserva título, párrafo y acciones, y la
  franja de demo sube a ocupar su lugar. La página nunca queda rota ni muestra
  un hueco vacío.
- **Responsive.** 1440 como el artboard · 1024: hero a ancho completo, franja a
  2+1, tira del día conserva proporción · 390: todo apilado, franja en tres
  bloques con filete entre ellos, tira del día pasa a lista vertical
  proporcional.
- **Props.** El contador es cliente puro, pero **la tira del día no**: dibuja
  ocupación real y por lo tanto necesita datos reales del servidor (§9.9).
  Contrato mínimo:

  ```
  timeline: {
    business_name:   string,
    employee_name:   string,          // nombre de pila
    date:            "YYYY-MM-DD",    // en la zona del negocio
    window:          { start: "09:00", end: "18:00" },
    slot_minutes:    int,             // granularidad del conteo de huecos
    occupied: [ { starts_at: "HH:MM",  // hora de pared local
                  ends_at:   "HH:MM",
                  duration_minutes: int,
                  service_name: string } ]
  } | null
  ```

  **Deliberadamente ausente:** nombre, correo o teléfono de cliente; id de
  reserva; estado de reserva; importe, seña o cualquier dato de pago; apellido o
  contacto del empleado. Los bloques ocupados se pintan neutros, sin color
  semántico, porque la tira comunica **ocupación**, no estado.

  Las horas viajan como cadenas `HH:MM` ya resueltas en la zona del negocio: el
  cliente no hace aritmética de zonas horarias y no recibe instantes UTC que
  pudieran revelar más de lo necesario.

  El nombre del servicio se conserva —está en el artboard aprobado y es
  catálogo público del negocio, ya visible en `/negocios/{slug}`—, pero queda
  anotado que en un despliegue con clientes reales esa proyección lo omitiría.

  Los huecos libres se derivan en el cliente restando `occupied` a `window` y
  dividiendo por `slot_minutes`: es aritmética sobre datos del servidor, no
  invención. **Prohibido** `fetch` desde el cliente y **prohibido** cualquier
  arreglo de horarios escrito a mano en React.
- **Prohibido.** Que el aviso de demo sea el hero. Caja de alerta gigante. El
  currículum del autor. Amarillo de advertencia decorativo.

### 8.2 `ComoFunciona` (nueva)

- **Ruta.** `GET /como-funciona` en `routes/public.php`, sin autenticación.
- **Secciones, en orden.** Título e introducción → "Es una demo compartida" +
  "Próximo reinicio" (dos columnas) → "Usá información ficticia" (único bloque
  ámbar, cuatro puntos en dos columnas) → "Dos recorridos sugeridos" (Recorrido A
  del negocio con credenciales visibles; Recorrido B del cliente; ambos numerados
  `01`–`05`) → "Los emails de la demo" (cuatro pasos + tarjeta de buzón
  compartido con el CTA) → pie con contacto.
- **La numeración `01`–`05` está justificada**: los recorridos son secuencias
  reales. No se usa numeración decorativa en ninguna otra parte.
- **Credenciales visibles.** `owner@reservahub.test` / `password`, con la línea
  "Cuenta ficticia, creada por el seeder. No es de nadie."
- **Debe responder** las once preguntas de operación: qué es, si es producción,
  si es compartido, cuándo se reinicia, cuánto falta, qué datos usar, cómo probar
  staff, cómo probar cliente, cómo probar el pago simulado, cómo ver los correos,
  qué hacer al cruzarse con datos ajenos.
- **Prohibido.** Explicar PostgreSQL, locks, Redis, colas, nombres de jobs, HMAC,
  protocolo de Reverb, Docker, cron, systemd o Cloudflare. Es una guía de
  producto, no documentación técnica.
- **Tiempo real** se demuestra por comportamiento: la introducción de los
  recorridos indica abrir una ventana normal y una de incógnito.
- **Responsive.** 1024: recorridos siguen en dos columnas · 390: todo apilado,
  las listas numeradas conservan la columna del número.

### 8.3 Familia de autenticación

Una sola familia: `Login`, `Register`, `ForgotPassword`, `ResetPassword`,
`VerifyEmail`, más `Invitations/Accept` y `Invitations/Unavailable`.

- **Layout compartido.** Cabecera pública simplificada, tarjeta centrada de
  460px sobre papel, pie con aviso de demo y contacto. `AuthCard` se rediseña
  conservando su nombre y su API (`title`, `children`).
- **Campos.** `FormField` con `label` asociada por `htmlFor`/`id`, `Input`,
  `InputError`. Los errores se anuncian con `aria-describedby`.
- **Comportamiento sin cambios.** Ninguna ruta, validación ni Action de auth se
  modifica.

**`Register` — el único con aviso.** Bajo el título, bloque ámbar: es una demo
pública compartida, usá nombre y correo inventados, y **una contraseña
descartable que no uses en ningún otro servicio**; todo se borra en el próximo
reinicio. El selector de tipo de cuenta pasa de dos radios sueltos a **dos
tarjetas seleccionables** —es la bifurcación más importante del formulario— con
`role="radiogroup"` y radios reales dentro. Bajo el campo de correo, una línea:
los correos llegan a un buzón compartido que cualquiera puede abrir.

**`Login` no lleva aviso.** Divulgación progresiva.

### 8.4 `Dashboard/Index`

- **Propósito.** Responder, en orden: qué pasa hoy, qué viene, qué está
  pendiente, qué requiere atención, dónde administro.
- **Layout.** `PageHeader` ("Panel", fecha larga y zona horaria del negocio) →
  fila de cifras (una dominante más tres subordinadas separadas por filetes
  verticales, **sin tarjetas**) → dos columnas: riel del día (izquierda) y cola
  de atención más atajos (derecha).
- **Cifras — todas consultas reales.**

  | Etiqueta | Consulta |
  |---|---|
  | `N turnos hoy` (dominante) | reservas cuyo `starts_at` cae hoy en `business.timezone`, cualquier estado |
  | desglose bajo la cifra | conteo por estado dentro de ese mismo conjunto |
  | `Esperando seña` | `status = pending` |
  | `Vence pronto` | `status = pending AND payment_expires_at > now() AND payment_expires_at <= now() + 15 min` |

  **La condición `> now()` no es opcional.** Sin ella, una reserva ya vencida
  cuenta como "vence pronto" durante la ventana que va desde el vencimiento
  hasta que `bookings:expire-unpaid` la procesa. Se muestra como urgente algo
  que ya caducó.

  Las dos métricas se solapan a propósito: `Esperando seña` es el total de
  pendientes y `Vence pronto` es su subconjunto urgente. El solapamiento en las
  **cifras** es intencional; en la **cola de atención** está prohibido (§9.1).
  | `Próximos 7 días` | `status = confirmed AND starts_at` entre mañana y +7 días |

  **Corrección de dominio incorporada.** `CreateBooking:67` fija
  `$status = $requiresDeposit ? Pending : Confirmed`: **`pending` solo existe
  cuando el servicio pide seña**. No hay reservas "esperando confirmación del
  negocio". Por eso la etiqueta es `Esperando seña` y no `Sin confirmar`, que
  sugeriría una acción de staff inexistente.

- **Riel del día.** Ventana **09:00–18:00** en la zona del negocio (§5.7), grilla
  horaria a 1,1 px/min — 66px por hora, 594px de alto. Bloques posicionados por
  `starts_at` y con altura `duration_minutes × 1,1`. Cada bloque: spine de
  estado, hora, servicio, empleado, cliente; los pendientes añaden importe y
  vencimiento de seña; los cancelados llevan hora tachada.
- **Cola de atención.** Una sola superficie con filas separadas por filete, cada
  una con spine de estado — misma anatomía que la lista de reservas.

  **Sin duplicados.** Una reserva aparece **una sola vez**. La clasificación es
  excluyente y priorizada: `expiring_soon` primero; `awaiting_deposit` recoge
  solo las pendientes que **no** quedaron clasificadas como `expiring_soon`.
  Cada fila lleva su `kind` y su micro-etiqueta correspondiente.

  **Estado vacío.** Con el dataset sembrado recién reiniciado la cola está
  vacía, porque el seeder no siembra reservas pendientes (§10.1). Muestra un
  `EmptyState` compacto: "Nada requiere atención" más una línea explicando que
  acá aparecen las reservas que esperan seña. **No es un caso degradado: es el
  estado normal de la demo hasta que un visitante reserva un servicio con
  seña.**
- **Atajos.** Tipografía sobre papel, sin superficie: cargar reserva, editar
  horarios, agregar feriado.
- **Rol.** `employee` ve el mismo layout con todas las consultas filtradas por
  `employee_id = self`. `owner`/`admin` ven el negocio completo.
- **Estados.** Vacío: `EmptyState` con "No hay turnos hoy" y acción
  `Cargar una reserva`. El riel muestra la grilla horaria vacía, no desaparece.
- **Responsive.** 1024: las cuatro cifras pasan a dos filas de dos · 390: cifra
  dominante arriba, las tres subordinadas apiladas, riel como lista vertical
  proporcional (sin grilla horaria), cola de atención a ancho completo.
- **Props nuevas** (§9.1).
- **Prohibido.** Porcentajes, tendencias, comparaciones con períodos anteriores,
  gráficos, ingresos estimados. Ninguna cifra sin consulta detrás.

### 8.5 `Dashboard/Bookings/Index`

Pantalla operativa central. **Conserva `BookingsRealtime` sin cambios de
contrato.**

- **Layout.** `PageHeader` ("Reservas", subtítulo "Se actualizan solas cuando
  cambia algo", acción `Nueva reserva`) → fila de filtros → grupos por día.
- **Grupos.** Micro-etiqueta en versalitas por día (`HOY · LUNES 24 DE AGOSTO`,
  luego el nombre del día) con filete a la derecha. Orden: hoy, futuras
  ascendentes, pasadas descendentes al final.
- **Fila.** Grilla de 7 columnas: spine 3px | hora + duración | servicio (+
  línea de seña si aplica) | empleado | cliente | badge de estado (+ línea de
  vencimiento si aplica) | acciones.
- **Acciones por estado.** `pending` → Confirmar, Reprogramar, Cancelar.
  `confirmed` → Completar, Ausencia, Reprogramar, Cancelar. Terminales → solo
  Ver. Se agrupan en `BookingActions`; las secundarias van tras un `IconButton`
  de menú.
- **Confirmaciones destructivas.** Cancelar y Ausencia usan `ConfirmDialog`, que
  reemplaza los `confirm()` nativos actuales. Foco inicial en la acción segura.
- **Reprogramar.** Deja de vivir dentro de una celda de tabla: pasa a `Modal` con
  selector de fecha y `SlotPicker`, alimentado por el endpoint
  `reschedule-slots` existente.
- **Filtros — servidor, no cliente.** `status`, `employee_id`, `from` por query
  string, aplicados en el controlador. Requieren test de backend.
- **Sin paginación.** Decisión deliberada a escala de demo, igual criterio que la
  Fase 10.5. El orden pasa a `starts_at` ascendente y el agrupado por día lo hace
  React sobre el conjunto completo.
- **Estados.** Vacío sin filtros: `EmptyState` "Todavía no hay reservas" +
  `Nueva reserva`. Vacío con filtros: "Ninguna reserva coincide" + `Limpiar
  filtros` — mensajes distintos, no el mismo.
- **Tiempo real.** Tras una recarga disparada por `booking.changed`, `Toast`
  discreto abajo a la izquierda: "Las reservas se actualizaron", auto-descarte a
  los 4 s, `role="status"`. **Sin badge "EN VIVO", sin indicador de conexión, sin
  terminología de Reverb.**
- **Responsive.** 1024: se ocultan las columnas Empleado y Cliente y pasan a una
  segunda línea bajo el servicio · 390: fila apilada — hora y estado en la
  primera línea, servicio en la segunda, empleado y cliente en la tercera,
  acciones en una fila de botones de 44px.

### 8.6 `Dashboard/Bookings/Show`

- **Jerarquía.** Estado y hora arriba → servicio, cliente, empleado →
  seña y pagos → acciones de ciclo de vida → historial.
- **Acciones.** Hoy solo existen en Index. Se agregan aquí con el mismo
  `BookingActions` y las mismas Policies.
- **Pagos.** `PaymentStatusBadge` en lugar del enum crudo. Se muestra importe,
  moneda, estado y, cuando aplica, la marca "cobrada sin aplicar"
  (`application_outcome === 'booking_not_pending'`). **No se exponen internals
  del proveedor**: ni `external_id`, ni snapshots, ni eventos de webhook.
- **Historial.** Lista de `status_histories` con hora, transición traducida,
  actor (`changed_by.name` o "sistema") y notas.
- **Estados.** Sin pagos: "Sin intentos de pago". Sin historial: no se renderiza
  la sección.
- **Responsive.** 1024 y 390: una sola columna; las acciones pasan a fila de
  botones de 44px.

### 8.7 `Dashboard/Bookings/Form`

- **Layout.** Formulario de una columna, máx. 560px, con resumen lateral en
  ≥1024px que muestra servicio, duración, precio y seña del servicio elegido.
- **Corrección funcional.** Hoy `create()` lista **todos** los empleados del
  negocio; el flujo público sí filtra por servicio (`BookingController::employeesFor`).
  Se unifica: el desplegable de empleado se restringe a los asignados al servicio
  elegido. Sin esto, `CreateBooking:45` rechaza la combinación con "Ese empleado
  no realiza este servicio" recién al guardar.
- **Slots.** `SlotPicker` en lugar de `<select>`, alimentado por la recarga
  parcial `only: ['slots']` ya existente.
- **Estados.** Cargando slots: skeleton de la grilla. Sin slots: "No hay horarios
  libres ese día" + sugerencia de probar otra fecha.
- **Responsive.** 390: resumen colapsa arriba del formulario; `SlotPicker` a 3
  por fila.

### 8.8 `Dashboard/Services/Index`

- **Ampliación (categoría C).** La tabla actual oculta `deposit_amount`,
  `buffer_minutes` e `is_active`, que ya vienen en la misma consulta.
- **Columnas.** Nombre (+ descripción truncada) | Duración | Buffer | Precio |
  Seña | Estado | acciones.
- **Moneda.** Formateada con `business.currency`, no `$` fijo.
- **Estado.** Los inactivos usan `StatusBadge` neutro y texto atenuado. Recordar
  que `ServiceController::index` ya filtra los inactivos para `employee`.
- **Rol.** Crear, editar y eliminar solo para managers, igual que hoy.
- **Estados.** Vacío: "Todavía no hay servicios" + `Nuevo servicio` (managers) o
  texto informativo (employee).
- **Responsive.** 1024: se oculta Buffer · 390: filas en tarjeta apilada.

### 8.9 `Dashboard/Services/Form`

- Una columna, campos agrupados en tres bloques: identidad (nombre,
  descripción), tiempo (duración, buffer), dinero (precio, seña) y estado
  (activo).
- Todas las etiquetas asociadas por `htmlFor`/`id` — hoy no lo están.
- El campo de seña explica su efecto: "Si tiene seña, la reserva queda pendiente
  hasta que se apruebe el pago."
- Prefijo de moneda tomado de `business.currency`.
- **Responsive.** Una columna en todos los anchos; máx. 560px.

### 8.10 `Dashboard/Employees/Index`

- **Layout.** Tres secciones con `PageHeader` y `SectionHeader`, no tres tablas
  crudas: Equipo → Invitaciones pendientes → Invitar.
- **Fila de empleado.** Nombre, correo, `StatusBadge` activo/inactivo, servicios
  asignados como chips, enlace a Horario, y acción activar/desactivar para
  managers vía `ConfirmDialog`.
- **Servicios asignados.** El formulario de checkboxes en línea pasa a `Modal`
  "Asignar servicios", disparado desde la fila.
- **Aviso de reservas futuras.** `future_bookings_count` deja de ser un `<p>`
  suelto y pasa a `Alert` con el conteo y la indicación de reasignar o cancelar.
- **Estados.** Sin empleados: `EmptyState` + `Invitar`. Sin invitaciones: línea
  discreta, no una tabla vacía.
- **Responsive.** 390: filas apiladas; chips de servicio con salto de línea.

### 8.11 `Dashboard/Employees/Schedule`

- **`ScheduleEditor`.** Vista semanal: siete filas de día, cada una con sus
  franjas y sus pausas anidadas. Reemplaza las cuatro tablas y tres formularios
  actuales.
- **Agregar franja** en línea por día, no en un formulario global con selector de
  día.
- **Pausas** anidadas visualmente dentro de su franja.
- **Licencias** en sección aparte, con fechas formateadas en la zona del negocio
  — hoy se muestran los timestamps crudos.
- **Eliminaciones** por `ConfirmDialog`.
- **Estados.** Día sin franjas: "Sin horario" atenuado, con acción de agregar.
  Sin licencias: `EmptyState` compacto.
- **Responsive.** 1024: igual · 390: cada día es una tarjeta apilada; los campos
  de hora a ancho completo, 44px.

### 8.12 `Dashboard/Holidays/Index`

- Formulario de alta arriba (nombre, desde, hasta), lista debajo.
- **La vista previa de conflictos es la joya de esta pantalla**: hoy
  `errors.bookings_preview` se renderiza como `<ul>` crudo. Pasa a `Alert` de
  advertencia con las hasta 5 reservas en conflicto y la explicación de que hay
  que cancelarlas o reprogramarlas antes.
- Fechas formateadas; rango de un solo día se muestra como una fecha, no como
  "X – X".
- Eliminación por `ConfirmDialog`.
- **Estados.** Vacío: `EmptyState` explicando que un feriado deja el día sin
  turnos.
- **Responsive.** 390: formulario apilado, lista en tarjetas.

### 8.13 `Dashboard/Settings/Edit`

- Secciones: Identidad (nombre, dirección pública de solo lectura), Operación
  (zona horaria, moneda), Reservas (horas mínimas para cancelar).
- Se conserva el aviso al cambiar zona horaria, ahora como `Alert`.
- **Corrección de defecto.** La línea actual muestra la dirección pública como
  `/businesses/{slug}` (`Settings/Edit.jsx:94`); la ruta real es
  `/negocios/{slug}` (`routes/public.php`). Se corrige el texto.
- Flash `status` pasa a `Toast`.
- **Responsive.** Una columna, máx. 640px.

### 8.14 `Account/Edit`

- Se mantiene la elección de layout por rol (staff → shell de panel, cliente →
  shell público), que ya es correcta.
- Dos secciones claramente separadas: Perfil y Contraseña. **No se mezclan con
  los ajustes del negocio.**
- Se renderiza `email_verified_at`: verificado con fecha, o `Alert` con acción de
  reenvío. Hoy la prop llega y se descarta.
- Se conserva el aviso de que cambiar el correo exige verificarlo de nuevo y el
  de que cambiar la contraseña cierra las demás sesiones.
- **Responsive.** Una columna, máx. 640px.

### 8.15 `Public/Business/Index`

- **Ampliación (categoría C).** Hoy devuelve solo `id, name, slug`: nada para
  elegir entre dos negocios.
- **Tarjeta de negocio.** Nombre, cantidad de servicios activos, rango de precios
  ("desde $3.500") y acción `Ver servicios`.
- **Prohibido.** Puntuaciones, reseñas, distintivos de popularidad, "más
  elegido", estrellas. No hay datos que los respalden.
- **Estados.** Vacío: `EmptyState` "Todavía no hay negocios disponibles".
- **Props nuevas** (§9.3).
- **Responsive.** 1440/1024: dos columnas · 390: una columna.

### 8.16 `Public/Business/Show`

- Encabezado del negocio y lista de `ServiceCard`.
- **`ServiceCard`.** Nombre, descripción, duración, precio en la moneda del
  negocio y, **cuando el servicio pide seña, el importe de la seña** — hoy no se
  anuncia en ninguna parte.
- Acción `Reservar` por servicio, que preserva el `service_id` en la query como
  hoy.
- **Estados.** Sin servicios activos: `EmptyState`.
- **Responsive.** 390: tarjetas apiladas, acción a ancho completo.

### 8.17 `Public/Business/Book` — flujo insignia

- **Layout.** Dos columnas en ≥1024px: pasos a la izquierda, resumen fijo a la
  derecha (380px).
- **Cuatro pasos, en secuencia real.** Servicio → Profesional → Fecha → Horario.
  Los resueltos se colapsan a una línea con acción `Cambiar`; el activo se marca
  con **borde de tinta**, no con spine de color (no hay estado que comunicar).
- **Fecha.** Tira de siete días con nombre y número; los días sin horario del
  negocio se muestran deshabilitados con la explicación ("Sábado y domingo el
  negocio no atiende").
- **Horario.** `SlotPicker`: grilla de chips de 44px, no un `<select>`. Nota al
  pie: "Solo aparecen los horarios en los que {empleado} tiene {duración}
  minutos libres seguidos."
- **Resumen — cierra la brecha C más seria.** Servicio, negocio, profesional,
  rango horario, fecha, duración, **precio, seña a pagar ahora y resto en el
  local**, más el bloque ámbar que explica que el turno queda pendiente y que hay
  30 minutos para pagar o se libera solo. Debajo del CTA: la ventana de
  cancelación y una línea sobre el buzón compartido de la demo.
- **La disponibilidad la calcula Laravel.** El cliente nunca la deriva ni la
  fabrica. Se conservan las recargas parciales `only: ['employees']` y
  `only: ['slots']`.
- **Estados.** Sin empleados para el servicio: mensaje explicativo. Sin slots:
  "No hay horarios libres ese día". Error de dominio (`starts_at` ya tomado):
  `Alert` con el mensaje del servidor y refresco de slots.
- **Responsive.** 1024: resumen pasa abajo de los pasos · 390: pasos apilados,
  tira de fechas con desplazamiento horizontal, `SlotPicker` a 3 por fila con
  chips de 48px, resumen colapsado en barra de acción fija al pie con hora, seña
  y CTA.

### 8.18 `Public/MyBookings/Index`

- **Agrupación.** Próximas → Pendientes de seña → Pasadas → Canceladas.
  Respaldada por `status`, `starts_at` y `payment` reales.
- **Tarjeta de reserva.** Negocio, servicio, profesional, fecha y hora,
  `BookingStatusBadge`, y cuando hay seña, `PaymentStatusBadge` con el importe.
- **Seña.** Botón `Pagar seña` cuando corresponde; `Continuar el pago` cuando hay
  un intento abierto con `checkout_url`. Ambos ya existen y se conservan.
- **Corrección.** Hoy la página recalcula el corte de cancelación en JS,
  duplicando `BookingPolicy::cancelOrReschedule`. Pasa a recibir del servidor un
  booleano por reserva (`can_cancel`, `can_reschedule`), derivado de la Policy.
  El frontend deja de tener una segunda implementación de la regla.
- **Sin tiempo real.** La Fase 10 acotó el broadcast a staff a propósito y esta
  fase **no** lo amplía.
- **Estados.** Vacío: `EmptyState` "Todavía no reservaste nada" + `Ver negocios`.
- **Responsive.** 390: tarjetas apiladas, acciones a 44px.

### 8.19 `Demo/Checkout`

- **Se preserva toda la arquitectura de la Fase 9.** Las rutas firmadas, el
  `outcome_url` temporal, el clamp de expiración y la entrega en proceso del
  webhook no se tocan.
- **Layout.** Aviso de pasarela simulada → título → importe grande con moneda →
  contexto de la reserva → estado y vencimiento → tres resultados → nota de
  cierre → pie.
- **Divulgación reforzada.** "No hay proveedor de pago real detrás de esta
  pantalla. No se cobra nada, no se pide número de tarjeta, código de seguridad
  ni datos bancarios, y no deberías ingresar ninguna información financiera acá."
- **Tres resultados** con su consecuencia escrita al lado: aprobar (la reserva
  pasa a confirmada), rechazar (sigue pendiente, se puede reintentar), abandonar
  (al vencer se cancela sola). El icono de cada uno porta el color semántico; los
  botones no son bloques de color.
- **Prohibido.** Campo de tarjeta, CVV, vencimiento, cuenta bancaria, imitación
  de Mercado Pago o Stripe, cualquier marca de pasarela real.
- **Estados.** Pago no pendiente: se ocultan los tres botones y se ofrece volver
  a Mis reservas.
- **Responsive.** Una columna, máx. 560px, botones a 44px.

### 8.20 `Invitations/Accept` y `Invitations/Unavailable`

Heredan la familia auth sin diseño propio. `Accept` conserva el nombre del
negocio en el título y el correo invitado como contexto. `Unavailable` conserva
su texto. Esfuerzo deliberadamente proporcionado.

---

## 9. Puentes de backend

Cada uno usa las Actions y Policies existentes, respeta `business_id` y **exige
test de backend**.

### 9.1 `DashboardController`

Hoy devuelve solo `{business: {id, name}}`. Pasa a devolver:

```
business: { id, name, timezone, currency }
metrics:  { today_total, today_by_status, awaiting_deposit,
            expiring_soon, upcoming_7d }
today:    [ { id, starts_at, ends_at, duration_minutes, status,
              service_name, employee_name, customer_name,
              deposit_amount, payment_expires_at } ]
attention:[ { id, kind, starts_at, status, service_name,
              employee_name, customer_name, deposit_amount,
              payment_expires_at } ]
```

Alcance por rol: `employee` filtra todo por `employee_id = self`.

**`kind` ∈ `expiring_soon | awaiting_deposit`, y la clasificación es excluyente.**
`expiring_soon` se resuelve primero; `awaiting_deposit` recoge las pendientes
restantes. Una misma reserva **nunca** aparece dos veces en `attention`, aunque
las métricas `awaiting_deposit` y `expiring_soon` sí se solapen a propósito.

`today` se ordena por `starts_at` ascendente. El riel es de una sola columna, así
que el dataset sembrado no contiene reservas superpuestas el mismo día (§10.2);
si dos llegaran a superponerse por acción de un visitante, se renderizan en el
orden recibido y se acepta el solape visual — no se introduce carriles por
empleado en esta fase.

**Tests.**

- Conteos correctos por estado.
- Ventana "hoy" resuelta en `business.timezone`, no en UTC.
- `employee` ve solo lo suyo; aislamiento entre negocios.
- **`expiring_soon` excluye una reserva ya vencida** (`payment_expires_at` en el
  pasado, todavía `pending` porque el scheduler no corrió).
- **`expiring_soon` incluye una reserva justo dentro de la ventana futura de 15
  minutos.**
- **`attention` no repite la misma reserva** cuando cumple ambos criterios.
- Con el dataset sembrado recién reiniciado, `awaiting_deposit`, `expiring_soon`
  y `attention` valen cero / vacío.

### 9.2 `Dashboard\BookingController::index`

Filtros por query string (`status`, `employee_id`, `from`) aplicados en el
controlador. Orden a `starts_at` ascendente. `businessId` se conserva tal cual
para `BookingsRealtime`.

**Tests.** Cada filtro acota el resultado; un `employee_id` de otro negocio no
filtra hacia datos ajenos; sin filtros el comportamiento equivale al actual.

### 9.3 `Public\BusinessController::index`

Agrega por negocio la cantidad de servicios activos y el precio mínimo, con
`withCount` y `min` — sin N+1.

**Tests.** Un negocio inactivo sigue sin aparecer (invariante de la Fase 10.5);
los conteos excluyen servicios inactivos.

### 9.4 `Public\BusinessController::show` y `BookingController::create`

`show` agrega `price` y `deposit_amount` a la proyección de servicios (ya los
tiene la tabla). `create` agrega `price`, `deposit_amount` y
`config('payments.window_minutes')` para que el resumen pueda anunciar la seña
antes de reservar.

**Tests.** Un servicio con seña expone su importe; uno sin seña expone `null`.

### 9.5 `Public\MyBookingsController::index`

Agrega `can_cancel` y `can_reschedule` por reserva, derivados de
`BookingPolicy`, para eliminar la reimplementación en JS.

**Tests.** Dentro y fuera de la ventana de cancelación; staff y cliente.

### 9.6 `Dashboard\BookingController::create`

Restringe la lista de empleados a los asignados al servicio seleccionado, igual
que el flujo público.

**Tests.** Un empleado no asignado no aparece.

### 9.7 `ComoFuncionaController`

Controlador de una sola acción, sin autenticación, que renderiza la guía. Solo
pasa lo que la vista necesita para el CTA del buzón; el contador es cliente puro.

**Tests.** Responde 200 para invitado y autenticado; renderiza el componente
esperado.

### 9.8 `HomeController` (nuevo)

Hoy `/` es una closure en `routes/web.php:7-9` que solo hace
`Inertia::render('Home')`. Pasa a un controlador de una sola acción
(`__invoke`), igual patrón que `DashboardController`, registrado en la misma
ruta y sin autenticación.

Devuelve `timeline` con el contrato de §8.1, o `null`.

**Selección determinista.** El primer negocio activo por nombre; dentro de él,
el primer empleado activo por nombre que tenga horario hoy. Sin horario, sin
empleados activos o sin negocios activos → `timeline: null` y el Home omite la
tira.

**Proyección mínima.** Solo `starts_at`, `ends_at`, `duration_minutes` y el
nombre del servicio de las reservas no canceladas de ese empleado hoy, más el
nombre del negocio y el nombre de pila del empleado. Las horas se formatean a
`HH:MM` en la zona del negocio dentro del controlador.

**Nunca proyecta** `customer_id`, nombre o correo de cliente, `id` de reserva,
`status`, `price`, `deposit_amount`, `payment_expires_at` ni relación de pago.

**Tests.**

- Devuelve `timeline: null` sin negocios activos, y la página igual responde 200.
- Devuelve `timeline: null` cuando el empleado elegido no tiene horario hoy.
- Con datos sembrados, los bloques ocupados coinciden con las reservas reales de
  ese empleado hoy.
- **Las reservas canceladas no aparecen** como ocupadas.
- **La respuesta no contiene** nombre de cliente, correo, id de reserva, estado
  ni ningún campo de pago — aserción explícita sobre las claves proyectadas.
- Un negocio inactivo nunca se elige.

### 9.9 Middleware compartido

`HandleInertiaRequests::share` agrega `auth.user.email` y el nombre del negocio
cuando hay contexto, para el shell. No se comparte nada más de forma global.

---

## 10. `DemoSeeder`

### 10.1 Arquitectura — factories, no Actions

`CreateBooking:55` rechaza horarios pasados y `:109` dispara `BookingCreated`.
Sembrar con Actions haría imposibles las reservas pasadas y **llenaría el buzón
compartido en cada reinicio de las 00:00**.

Por eso: **factories más filas explícitas de `BookingStatusHistory`**, con
eventos suprimidos. `bookings.starts_at`/`ends_at` se persisten en **UTC**
(`CreateBooking:89`): el seeder calcula hora de pared en la zona del negocio y
convierte. `price` y `deposit_amount` son snapshot en la reserva.

Se conserva la idempotencia por slug.

### 10.1.1 Ninguna reserva pendiente sembrada — y por qué

El seeder **no siembra reservas en estado `pending`**, y por lo tanto tampoco
pagos `pending`.

La razón es de dominio, no de comodidad. Una reserva con seña recibe una
`payment_expires_at` acotada (`config('payments.window_minutes')`, 30 por
defecto) y `bookings:expire-unpaid` la cancela al vencer. El reinicio corre a las
00:00 y la demo se usa durante todo el día: una reserva pendiente sembrada a
medianoche estaría cancelada mucho antes de que alguien abra la página. El
"estado inicial conocido" duraría media hora.

Las salidas fáciles quedan **explícitamente prohibidas** porque volverían el
dataset inconsistente con el dominio real: alargar artificialmente la ventana de
pago, sembrar `created_at` en el futuro, dejar `payment_expires_at` en `null`
para una reserva que sí pide seña, desactivar la expiración automática, o tocar
cualquier comportamiento de la Fase 9.

**El estado pendiente lo genera el visitante**, recorriendo el flujo real — que
además es el mejor momento de la demo:

```
cliente reserva un servicio con seña
    → aparece pendiente
    → la pantalla del negocio se actualiza sola (Fase 10)
    → el visitante abre el checkout simulado
    → aprueba el pago
    → la reserva pasa a confirmada
    → la pantalla del negocio se actualiza de nuevo
```

En consecuencia, el panel recién reiniciado muestra legítimamente
**`Esperando seña = 0`** y **`Vence pronto = 0`**, con la cola de atención en su
estado vacío. **Ninguna métrica se infla artificialmente para no mostrar cero.**

Para capturas de pantalla se crea una reserva pendiente real por el flujo normal
justo antes de capturar. **No** se hornea en el seeder un estado pendiente que el
dominio no puede sostener.

### 10.2 Dataset

**Dos negocios.** Peluquería Demo (`peluqueria-demo`) y Estudio Demo
(`estudio-demo`), ambos ARS / `America/Argentina/Buenos_Aires` /
`cancellation_hours = 24`.

**Staff.** Peluquería: owner (`owner@reservahub.test`), **admin
(`admin@reservahub.test`, nuevo)**, dos empleados (`ana@`, `beto@`). Estudio:
owner (`owner2@`), un empleado (`carla@`). El admin hace demostrable el rol y el
invariante de `UserPolicy` de que un admin no puede cambiar el estado de un
owner.

**Clientes.** Cuatro, `business_id` nulo, compartidos: `marina@`, `julian@`,
`lucia@`, `rodrigo@` (Marina Ruiz, Julián Paz, Lucía Gil, Rodrigo Sosa). Marina
es la que se ofrece al visitante y ya tiene reservas. Contraseña `password`,
dominio `.test`.

**Servicios.** Peluquería: Corte 30′ $3.500 · **Coloración 90′ $12.000, seña
$2.400** · Manicura 45′ $4.000 · Masaje 60′ $8.000 · Depilación 30′ $5.000.
Estudio: Clase de guitarra 60′ $6.000 · **Grabación de demo 120′ $20.000, seña
$5.000**. Seña solo en los dos servicios largos y caros.

**Horarios.** Ana y Beto: **los siete días, 09:00–18:00**, con pausa
13:00–14:00 todos los días. Carla: **lunes a viernes** 09:00–18:00, misma pausa.

Peluquería abre los siete días con horario idéntico **a propósito y por dos
motivos**. Primero, el reinicio corre a diario y el panel tiene que ser
significativo también un domingo. Segundo, y decisivo: las reservas de hoy se
siembran con horas de pared fijas (09:00 … 17:30), así que el horario del
empleado tiene que ser el mismo cualquier día de la semana o el dataset se
volvería inválido los fines de semana. Un horario reducido de domingo dejaría la
reserva de las 09:00 y la de las 17:30 fuera de la jornada de su empleado.

Estudio queda de lunes a viernes, así el caso "día cerrado, sin disponibilidad"
sigue siendo demostrable sin comprometer la demo principal.

La ventana 09:00–18:00 coincide exactamente con la ventana canónica del riel
(§5.7): ninguna reserva sembrada puede quedar recortada.

**Licencia.** Beto, tres días desde hoy+10. **Feriado.** Peluquería, un día en
hoy+14. Ambas tablas dejan de estar vacías.

**Reservas — 23 en total (21 Peluquería + 2 Estudio), todas en estado estable.**

Peluquería, hoy (6): 5 confirmadas, 1 cancelada. **Ninguna pendiente** (§10.1.1).

| Horario | Servicio · empleado | Cliente | Estado |
|---|---|---|---|
| 09:00–09:30 | Corte · Ana | Marina | confirmada |
| 10:00–11:30 | Coloración · Ana | Lucía | confirmada, **con seña aprobada** |
| 12:00–12:30 | Corte · Beto | Rodrigo | confirmada |
| 15:00–15:45 | Manicura · Ana | Rodrigo | confirmada |
| 16:30–17:30 | Masaje · Beto | Marina | confirmada |
| 17:30–18:00 | Depilación · Ana | Julián | cancelada |

Verificado contra horarios, pausas de 13:00–14:00 y buffers: sin choques con el
almuerzo. **Y verificado contra el riel de una sola columna: ninguna de las seis
se superpone con otra**, ni siquiera entre empleados distintos. La última
termina exactamente a las 18:00, el borde de la ventana canónica.

Peluquería, mañana (3) confirmadas. Días +2 a +6 (8) confirmadas — dan
**`Próximos 7 días` = 11**, que es la métrica de mañana a +7 y por lo tanto
**no** incluye las de hoy.

Peluquería, pasadas (4): −7 d Coloración **completada con pago aprobado** (camino
feliz completo en `Show`) · −7 d Manicura **ausencia** · −3 d Corte completada ·
−2 d Masaje cancelada.

Estudio (2), **sin reservas hoy**: Grabación **confirmada con seña aprobada** en
el primer día hábil a partir de hoy+3 · Clase de guitarra completada en el
último día hábil anterior a hoy−5.

Ambas se ajustan al día hábil más cercano porque Carla trabaja de lunes a
viernes: sin ese ajuste, un reinicio en fin de semana dejaría reservas fuera de
su jornada. Peluquería no necesita el ajuste porque abre los siete días.

Que Estudio no tenga reservas hoy es **deliberado y útil**: hace demostrable el
estado vacío del panel y, al mismo tiempo, el aislamiento entre negocios sigue
siendo evidente — `owner@` ve seis turnos hoy y `owner2@` ve cero, con datos
completamente distintos en cada panel.

**Pagos — tres, todos aprobados.**

| Reserva | `payments` | `simulated_provider_payments` |
|---|---|---|
| hoy 10:00 Coloración | approved, `paid_at` | approved |
| −7 d Coloración | approved, `paid_at` | approved |
| Estudio +3 d Grabación | approved, `paid_at` | approved |

**Cero pagos `pending` sembrados**, coherente con §10.1.1. El índice parcial
`payments_one_pending_per_booking` queda intacto y nada tiene que expirar tras el
reinicio.

Las filas del proveedor son **obligatorias**: sin ellas `fetchPayment()` da 404 y
el checkout no abre.

**Métricas resultantes del panel de Peluquería recién reiniciado:**
`6 turnos hoy` · `5 confirmadas · 1 cancelada` · `Esperando seña 0` ·
`Vence pronto 0` · `Próximos 7 días 11` · cola de atención vacía.

**Invitación.** Una `EmployeeInvitation` pendiente en Peluquería.

**Correos: ninguno sembrado.** El buzón arranca vacío y se llena con lo que hagan
los visitantes. Sembrar notificaciones exigiría despachar eventos, justo lo que
la arquitectura de §10.1 descarta.

**No se siembra** relleno para alargar tablas, `webhook_events`,
`notifications`, recordatorios ni un tercer negocio.

### 10.3 Tests del seeder

- Idempotencia por slug.
- Conteos por negocio: **21 en Peluquería, 2 en Estudio** (23 en total).
- Estudio **no tiene reservas hoy**, y las que tiene caen en día hábil.
- Los dos servicios con seña tienen `deposit_amount`; los otros cinco, `null`.
- Los cinco estados de reserva están presentes en el dataset completo
  (confirmada y cancelada hoy; completada y ausencia en el pasado; **`pending`
  deliberadamente ausente**).
- **Ninguna reserva sembrada queda en `pending`** y **ningún pago sembrado queda
  en `pending`** — es el invariante de §10.1.1 y el que evita que el estado
  inicial se degrade solo tras el reinicio.
- Consistencia `payments` ↔ `simulated_provider_payments` en las tres filas.
- **`Notification::fake()` + `assertNothingSent()`**: un reinicio no manda
  correo.
- Toda reserva de hoy cae dentro del horario de su empleado y fuera de su pausa.
- **Ninguna reserva de hoy se superpone con otra**, requisito del riel de una
  sola columna.
- Toda reserva de hoy cae dentro de la ventana 09:00–18:00.
- Las métricas del panel sobre el dataset recién sembrado dan exactamente
  `6 / 5 confirmadas / 1 cancelada / 0 esperando seña / 0 vence pronto / 11
  próximos 7 días`.

### 10.4 Nota de despliegue

El reinicio debe ejecutar `php artisan db:seed --class=DemoSeeder`, **nunca**
`DatabaseSeeder`, que además crea `test@example.com`.

---

## 11. Estados de UX

Cada pantalla importante define seis estados. Ninguno queda a criterio de la
implementación.

| Estado | Tratamiento |
|---|---|
| Normal | según §8 |
| Vacío | `EmptyState`: título, una línea de explicación y **la acción siguiente**. Vacío por filtro y vacío real usan textos distintos |
| Cargando | comportamiento nativo de Inertia: barra de progreso de navegación y botones deshabilitados con `processing`. Skeleton **solo** en `SlotPicker` y el riel del día, donde el contenido llega por recarga parcial |
| Validación | `FormField` + `InputError` con `aria-describedby`. Los mensajes del servidor se muestran tal cual, en español |
| Error de dominio | `Alert` con el mensaje de la `ValidationException` (por ejemplo "Ese horario ya no está disponible") y refresco del dato afectado |
| Confirmación destructiva | `ConfirmDialog` con el contrato de §5.9; **foco inicial en la acción segura** |
| Éxito | `Toast` de 4 s, `role="status"`. Reemplaza los flash `status` renderizados como `<p>` verde |

**Prohibido.** Animaciones de carga falsas sobre datos que Inertia ya entregó.
Mensajes genéricos "Error" que tapen la validación del servidor. Mensajes de
excepción crudos.

---

## 12. Responsive

Anchos de verificación: **~1440**, **~1024**, **~390**.

| Elemento | 1440 | 1024 | 390 |
|---|---|---|---|
| Barra lateral del panel | 240px fija | riel de 64px colapsable, recordado | drawer detrás de `≡` |
| Franja de demo del Home | 3 columnas + enlace | 2+1 | apilada con filetes |
| Tira del día del Home | pista de 1120px | proporción conservada | lista vertical proporcional |
| Lista de reservas | 7 columnas | se ocultan Empleado y Cliente, pasan a segunda línea | fila apilada en 3 líneas |
| Riel del día del panel | grilla horaria completa | igual | lista proporcional sin grilla |
| Cifras del panel | 1 dominante + 3 en línea | 2 filas de 2 | apiladas |
| Recorridos de `/como-funciona` | 2 columnas | 2 columnas | apilados |
| `SlotPicker` | 6 por fila | 4 por fila | 3 por fila, chips de 48px |
| Resumen de reserva | columna fija de 380px | debajo de los pasos | barra de acción fija al pie |
| Tablas de servicios/personal | tabla | tabla con columnas reducidas | tarjetas apiladas |

**Reglas duras.** Sin desplazamiento horizontal de página en ningún ancho. Los
objetivos táctiles del flujo público son **≥44px**. Las divulgaciones de demo
nunca se convierten en un muro vertical de advertencias: una línea por
superficie.

---

## 13. Accesibilidad

- **Encabezados.** Un `h1` por página, sin saltos de nivel.
- **Landmarks.** `header`, `nav`, `main`, `footer` en ambos shells.
- **Formularios.** Toda etiqueta asociada por `htmlFor`/`id`. Errores por
  `aria-describedby`. Grupos de radio con `role="radiogroup"` y nombre accesible.
- **Botones y enlaces.** Un enlace navega, un botón actúa. Las acciones de ciclo
  de vida son `<button>`; `Ver` es `<Link>`.
- **Foco.** Anillo de 2px tinta con separación blanca, visible sobre papel y
  sobre tinta. Nunca `outline: none` sin reemplazo.
- **Diálogos.** El contrato completo de §5.9.
- **Iconos solos.** Todo `IconButton` lleva `aria-label`.
- **Estado.** Nunca solo por color: siempre color más icono más etiqueta.
  "Compartida" y "pública" siempre en texto.
- **Contador.** Sin `aria-live`, sin cambio de foco, sin salto de layout.
- **Movimiento.** `prefers-reduced-motion: reduce` desactiva transiciones de
  drawer y diálogo y deja los cambios de estado instantáneos.
- **Contraste.** Verificado por cálculo y **obligatoriamente confirmado en
  navegador**:

  | Par | Ratio | AA |
  |---|---|---|
  | `--muted` sobre `--bg` | **4,79:1** | ✔ ajustado |
  | `--muted` sobre `--surface` | 5,64:1 | ✔ |
  | `--fg` sobre `--bg` | ~15:1 | ✔ |
  | `#92400E` sobre `#FEF3C7` | ~7,5:1 | ✔ |
  | `#78350F` sobre `#FFFBEB` | ~9:1 | ✔ |

  El primero es el par más ajustado del sistema. **Si el papel se aclara alguna
  vez, hay que recalcularlo.**

---

## 14. Rendimiento

- Bundle de referencia: 446,79 kB / 129,51 kB gzip. Al no agregar dependencias de
  runtime, el crecimiento esperado es el del código propio.
- Sin librería de fechas, sin librería de componentes, sin cliente de datos.
- Se vigilan N+1 que la UI nueva pueda destapar: el conteo de servicios del
  descubrimiento público usa `withCount`; el panel carga las relaciones que
  proyecta.
- No se optimizan cuellos teóricos.

---

## 15. Documentación entregable

- **`docs/DEPLOYMENT_HANDOFF.md`** — la Fase 11 reescribe §3, §4, §9 y §10 y
  agrega una sección de reinicio diario. Los cambios obligatorios:
  - §3 deja de exigir SMTP real: en el despliegue público de portfolio, Mailpit
    es el destino SMTP previsto y su interfaz es superficie de producto.
  - §4 documenta `VITE_DEMO_MAIL_URL` como **pública, no secreta**, compilada en
    el bundle, con la misma nota de "exige `pnpm build`" que las `VITE_REVERB_*`.
  - §9 saca Mailpit de *Qué no debe exponerse nunca* — hoy dice, en las líneas 59
    y 190, que no debe desplegarse, lo que contradice el modelo aprobado.
  - §10 incorpora el buzón público, el reinicio diario, las limitaciones
    aceptadas de §7.5 y el acoplamiento del horario del contador.
- **`.env.example`** — declara `VITE_DEMO_MAIL_URL` con su comentario.
- **`01-reservahub.md`** — tabla de estado: Fase 11 pasa a Hecha; se anota que la
  expansión del `DemoSeeder` con clientes y reservas la cerró la Fase 11 y ya no
  es pendiente de la Fase 12.
- **Documento de decisiones de diseño frontend** derivado de este spec.

---

## 16. Defectos encontrados y su corrección

### 16.1 Cinco tests dependientes del calendario

`Tests\Feature\Public\MyBookingsTest` (2) y `Tests\Feature\Api\BookingsWriteTest`
(3) fallan cuando la suite corre un domingo a partir de las 09:00 UTC.

Causa: construyen la reserva con `CarbonImmutable::parse('next monday')` a las
09:00 con `cancellation_hours = 24`. Un domingo por la tarde el corte ya pasó y
`BookingPolicy::cancelOrReschedule` devuelve 403 — correctamente. La prueba de
que es el test y no la aplicación: `customer cannot cancel past the cancellation
window`, que afirma lo contrario, **pasa**.

Corrección: congelar el tiempo con `travelTo` en los cinco. **No se toca lógica
de dominio.** Es reparación de la infraestructura de verificación: el §76 exige
suite verde como condición de cierre, y una suite que falla los domingos no
permite distinguir una regresión del rediseño del día de la semana.

### 16.2 Ruta pública mal escrita en Ajustes

`Settings/Edit.jsx:94` muestra la dirección pública como `/businesses/{slug}`.
La ruta real es `/negocios/{slug}`. Se corrige el texto.

### 16.3 Empleados no filtrados en el alta de reserva del panel

`Dashboard\BookingController::create` lista todos los empleados del negocio,
mientras el flujo público filtra por servicio. Se unifica (§9.6).

### 16.4 Regla de cancelación duplicada en el cliente

`Public/MyBookings/Index.jsx` reimplementa el corte de `BookingPolicy` en
JavaScript. Se reemplaza por booleanos del servidor (§9.5).

---

## 17. Verificación de la fase

1. Tests de backend dirigidos para cada puente de §9 y para el seeder (§10.3).
2. Corrección de los cinco tests de §16.1.
3. Suite Laravel completa en verde.
4. `vendor/bin/pint --test`.
5. `pnpm build`.
6. Revisión visual con la skill `frontend-design`.
7. **Inspección en navegador real** de las quince pantallas principales a ~1440,
   ~1024 y ~390, comparadas contra los artboards aprobados.
8. Revisión de teclado y foco.
9. Revisión de accesibilidad según §13, con contraste confirmado en navegador.
10. Smoke manual del recorrido de staff.
11. Smoke manual del recorrido de cliente.
12. Smoke manual del pago simulado, incluido el camino de abandono.
13. Smoke de tiempo real de la Fase 10 con dos ventanas, incluido el aislamiento
    entre negocios.
14. Verificación de que ninguna cifra visible carece de consulta detrás.
15. Revisión final de toda la rama.

**Sin Playwright, Cypress, Vitest ni Jest en esta fase.**

---

## 18. Fuera de alcance

**Fase 12 (deja listo, no despliega).** CI que valida el repo en GitHub · README
propio · contrato de entorno cerrado y verificado · procedimiento exacto del
reinicio documentado · claves de retención de Mailpit nombradas sin valores ·
candidato `php artisan demo:reset` con guarda explícita · reconsideración de
Playwright sobre una UI ya estable · capturas posteriores al rediseño.

**Operaciones (workflow externo, ejecuta).** `.env` de producción y sus secretos ·
el valor real de `VITE_DEMO_MAIL_URL` —que **no** es un secreto— y el `pnpm build`
que lo compila · hostname `mail.`, DNS,
Cloudflare Tunnel, proxy inverso · programación del reinicio · vaciado del buzón,
retención, ocultar "Delete all" · migraciones y seed sobre el servidor · smoke de
despliegue, rollback, backups.

**Nunca en la Fase 11.** Next.js · segunda SPA · SSR · Redux/Zustand/MobX ·
React Query · librería de componentes · librería de fechas · tiempo real para
clientes · rediseño de Reverb · proveedor de pago real · cambios en la
arquitectura de pagos · sistema de analítica · métricas inventadas · reseñas o
puntuaciones · marketplace · suscripciones · planes · chat · centro de
notificaciones · librería de calendario por estética · aislamiento por visitante
· buzón por visitante · visor de correo propio · sincronización backend del
contador · API de estado de reinicio · botón público de reinicio · E2E.
