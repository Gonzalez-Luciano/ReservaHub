# Fase 5 — Reservas

## Objetivo

Dar de alta reservas reales: motor de creación/gestión de `bookings` con overlap-safety transaccional (re-chequeo de disponibilidad dentro de la transacción, protegido contra condiciones de carrera), más las dos superficies de UI que lo consumen — dashboard de staff y página pública self-service de cliente — reusando `App\Services\AvailabilityService` (Fase 4) en ambas.

## Alcance

- Migración `booking_status_histories`.
- `App\Actions\Bookings\{CreateBooking,ConfirmBooking,CancelBooking,RescheduleBooking,CompleteBooking,MarkNoShow}`.
- `App\Policies\BookingPolicy`.
- Evento `App\Events\BookingCreated`.
- `App\Http\Controllers\Dashboard\BookingController` + rutas bajo `dashboard/bookings/*` (middleware `business`).
- `App\Http\Controllers\Public\{BusinessController,BookingController,MyBookingsController}` + rutas nuevas en `routes/public.php` (sin middleware `business`; resuelven negocio por slug).
- Form Requests: `Dashboard\BookingRequest` (alta manual), `Public\BookingRequest` (self-service), `RescheduleBookingRequest` (compartido).
- UI: `Dashboard/Bookings/{Index,Form,Show}.jsx`, `Public/Business/{Show,Book}.jsx`, `Public/MyBookings/Index.jsx`.

## Fuera de alcance

- Endpoint `GET /api/availability` → Fase 7 (API + Sanctum).
- Notificaciones/recordatorios de reserva → Fase 6.
- Confirmación automática de `pending` vía webhook de pago → Fase 8.
- Listado/búsqueda pública de negocios (solo URL directa por slug esta fase).
- Self-service de empleado (ver/gestionar su propio horario) → no es parte de esta fase.
- Creación de cuentas de customer nuevas desde el dashboard (el customer ya se registró antes, vía Fase 2).

## Decisiones

| Decisión | Elegido | Alternativa descartada | Por qué |
|---|---|---|---|
| Alcance de UI | Dashboard (staff) **y** página pública self-service (cliente) en la misma fase, un solo spec/plan | Partir en dos fases/specs secuenciales | El spec menciona "origen: web, administración o API" como algo ya conceptualmente presente; se decide cubrir ambos orígenes reales (web=cliente, admin=staff) de punta a punta en una sola fase |
| "Crear historial" (paso 7 del spec, sin tabla definida en §3) | Tabla nueva `booking_status_histories` | Reusar paquete de activity log genérico / no persistir historial | Consultable directo sin dependencia nueva; cumple literalmente el paso del spec y sirve para timeline en UI |
| Estado inicial de una reserva creada | `confirmed` si `service.deposit_amount` es null/0; `pending` si tiene seña requerida | Siempre `pending`, confirmación manual de staff | Sigue la regla ya escrita en CLAUDE.md ("a booking with a required deposit stays pending until payment confirmed"); sin seña no hay razón de negocio para bloquear la confirmación a la espera de un gateway que recién llega en Fase 8 |
| `RescheduleBooking` | Actualiza `starts_at`/`ends_at` en la misma fila + entrada en `booking_status_histories` con nota del horario viejo→nuevo | Cancelar la reserva vieja y crear una nueva | Un solo registro de reserva por turno real; evita mezclar cancelaciones genuinas con reprogramaciones en reportes futuros |
| Actions de dominio no listadas en el árbol sugerido del spec (`Completar`, `Marcar ausencia`) | Se agregan `CompleteBooking` y `MarkNoShow` como Actions propias, disparadas manualmente por staff | Diferir a una fase futura | El árbol del spec (líneas 286-290) es sugerido, no exhaustivo; sin estas Actions los estados `completed`/`no_show` del dominio (líneas 88-96) nunca serían alcanzables desde la UI |
| Concurrencia (re-chequeo dentro de tx) | `pg_advisory_xact_lock(hashtext(employee_id))` al inicio de la transacción de `CreateBooking`/`RescheduleBooking` | `SELECT ... FOR UPDATE` sobre bookings existentes | `FOR UPDATE` no lockea nada cuando el slot está vacío (no hay fila que lockear) — dos requests concurrentes pasarían el re-chequeo a la vez. El advisory lock serializa por empleado sin necesitar filas preexistentes, y se libera solo al hacer commit/rollback |
| Elección de empleado en self-service | Cliente elige explícitamente (servicio → empleado → slot) | "Cualquiera disponible" (búsqueda multi-empleado automática) | `AvailabilityService::getAvailableSlots()` exige `employee` obligatorio por decisión de Fase 4 ("búsqueda multi-empleado es un caso de uso posterior"); no se toca esa firma en esta fase |
| Campo `notes` (notas internas) | Solo staff lee/escribe, desde el dashboard; el form público de reserva no lo incluye | Cliente puede agregar una nota al reservar | Evita mezclar notas de cliente con notas internas de staff en la misma columna sin forma de distinguir el origen |
| Descubrimiento de negocio (self-service) | URL directa por slug (`/negocios/{slug}`), sin listado ni buscador | Agregar también un listado público de negocios | El spec de Fase 5 no pide una feature de discovery; se mantiene la fase acotada al motor de reservas |
| `BookingPolicy` — ver/cancelar/reprogramar | Customer: solo la propia. Staff: todas las de su negocio. `cancellation_hours` aplica solo a customer, no a staff | `cancellation_hours` aplica también a staff | Caso operativo: el negocio puede necesitar cancelar/reprogramar fuera de la ventana (ej. cierre de emergencia); el límite existe para proteger al negocio de cancelaciones tardías del cliente, no al revés |
| `BookingPolicy` — confirmar/completar/marcar ausencia | Solo staff del mismo negocio, nunca customer | Permitir que el customer se autoconfirme | Ninguna de estas transiciones tiene sentido de negocio disparada por el cliente; son operativas del negocio |
| Alta manual desde dashboard | Staff (owner/admin/employee — no solo managers) elige un customer *existente* vía búsqueda | Elevar la política a solo managers | Un empleado de mostrador también necesita cargar turnos por teléfono/presenciales |
| Evento `BookingCreated` | Evento simple sin `ShouldBroadcast`, sin listeners todavía | Implementar ya notificación/broadcast | Notificaciones son Fase 6, broadcasting en tiempo real es Fase 9; el evento solo deja el punto de extensión listo |

## Modelo de datos

```
booking_status_histories
  id, booking_id (FK bookings, cascadeOnDelete),
  from_status nullable string, to_status string,
  changed_by nullable (FK users, nullOnDelete),
  notes nullable text, created_at

  index: booking_id
  → sin business_id propio, se autoriza vía booking->business_id (mismo patrón que schedule_breaks de Fase 3)
```

`App\Models\BookingStatusHistory`: relaciones `booking()`, `changedBy()` (→ `User`); casts `from_status`/`to_status` → `BookingStatus` (nullable en `from_status`).

No hay cambios en la tabla `bookings` (Fase 4 ya la dejó completa).

## Actions (`app/Actions/Bookings/`)

**`CreateBooking::handle(Business $business, array $data): Booking`**

Dentro de `DB::transaction()`:
1. `pg_advisory_xact_lock(hashtext($employeeId))`.
2. Re-validar con `AvailabilityService::getAvailableSlots()` que el slot pedido siga libre → si no, excepción de dominio.
3. Determinar status inicial: `confirmed` si `service.deposit_amount` es null/0, si no `pending`.
4. Crear `Booking`.
5. Insertar `BookingStatusHistory` (`from_status: null`, `to_status`, `changed_by: $actingUser->id`).
6. Disparar `BookingCreated`.

**`RescheduleBooking::handle(Booking $booking, array $data): Booking`** — misma protección de concurrencia (advisory lock + re-validar el nuevo slot dentro de tx) que `CreateBooking`; `UPDATE` de `starts_at`/`ends_at` en la misma fila; inserta history con nota del horario viejo→nuevo.

**`ConfirmBooking`, `CancelBooking`, `CompleteBooking`, `MarkNoShow`** — cada una valida la transición de estado permitida, actualiza (`CancelBooking` también setea `cancelled_at`), inserta history. Sin re-chequeo de disponibilidad (no mueven horario). `CancelBooking` valida `cancellation_hours` cuando `changed_by` es customer.

Transiciones válidas: `pending|confirmed → cancelled`, `pending → confirmed`, `confirmed → completed`, `confirmed → no_show`. Cualquier otra combinación lanza excepción de dominio.

## Policy (`App\Policies\BookingPolicy`)

- `view`: customer dueño (`booking.customer_id === user.id`) o staff del mismo `business_id`.
- `createByStaff(User $user, Business $business)`: cualquier staff (owner/admin/employee) de `$business`.
- `createByCustomer(User $user)`: `user.role === Customer`.
- `cancel` / `reschedule`: customer dueño y dentro de `cancellation_hours`, o staff del mismo negocio (sin límite de horas).
- `confirm` / `complete` / `markNoShow`: solo staff del mismo negocio.

## Evento

`App\Events\BookingCreated` (payload: `Booking`), sin `ShouldBroadcast` ni listeners todavía. Se dispara desde `CreateBooking`.

## Rutas

**Dashboard** (agregado a `routes/dashboard.php`, dentro del grupo `business`):
```
GET/POST   dashboard/bookings, dashboard/bookings/create   (index, create, store)
GET        dashboard/bookings/{booking}                     (show)
POST       dashboard/bookings/{booking}/confirm
POST       dashboard/bookings/{booking}/cancel
POST       dashboard/bookings/{booking}/complete
POST       dashboard/bookings/{booking}/no-show
PUT        dashboard/bookings/{booking}/reschedule
```

**Público** (`routes/public.php` nuevo, sin middleware `business`):
```
GET   negocios/{business:slug}                     Public\BusinessController@show
GET   negocios/{business:slug}/reservar             Public\BookingController@create
POST  negocios/{business:slug}/reservar             Public\BookingController@store   (auth, role=customer)
GET   mis-reservas                                  Public\MyBookingsController@index (auth, role=customer)
POST  mis-reservas/{booking}/cancel
PUT   mis-reservas/{booking}/reschedule
```

## UI

- `Dashboard/Bookings/Index.jsx` — filtro por estado/fecha/empleado, botones de acción (confirmar/cancelar/completar/no-show/reprogramar).
- `Dashboard/Bookings/Form.jsx` — alta manual: busca customer existente + servicio + empleado + fecha/slot + notas internas.
- `Dashboard/Bookings/Show.jsx` — detalle + timeline de `booking_status_histories`.
- `Public/Business/Show.jsx` — info del negocio + lista de servicios activos, link "Reservar" por servicio.
- `Public/Business/Book.jsx` — wizard servicio → empleado (`Service::employees()`) → fecha → slots (recarga parcial Inertia al cambiar fecha/empleado) → confirmar.
- `Public/MyBookings/Index.jsx` — reservas del customer logueado, cancelar/reprogramar (deshabilitado fuera de `cancellation_hours`). Reprogramar abre el mismo selector de slots del wizard de `Book.jsx` (componente compartido), sin página propia — solo hay rutas `PUT` de reprogramación, no `GET`.

## Testing

**Feature tests** (cubren los explícitos del spec §8):
- Cliente crea reserva válida (self-service).
- Reserva fuera de horario falla.
- Reserva solapada falla.
- Reserva durante licencia falla.
- Cancelación tardía falla (customer, dentro de `cancellation_hours`).
- Staff crea reserva manual desde dashboard (`source=admin`).
- Employee no gestiona reservas de otro negocio (403, cross-business).
- Confirm/Complete/MarkNoShow respetan transiciones válidas; transición inválida lanza excepción de dominio.
- Reschedule revalida disponibilidad del nuevo slot y actualiza la misma fila.
- `BookingCreated` se dispara al crear (`Event::fake()`).
- Cada cambio de estado inserta fila en `booking_status_histories` con `from_status`/`to_status`/`changed_by` correctos.
- Servicio sin `deposit_amount` nace `confirmed`; con `deposit_amount` nace `pending`.

**Test de concurrencia** (spec §8 "Concurrencia": "simular dos solicitudes para el mismo turno y verificar que solamente una se confirme"): dos conexiones DB separadas disparan `CreateBooking` para el mismo slot casi simultáneamente; se asserta que exactamente una reserva queda creada y la otra lanza la excepción de disponibilidad — ejercita el `pg_advisory_xact_lock` real, no un mock.

**Unit tests:** transiciones de estado válidas/inválidas en cada Action, `BookingPolicy` en aislamiento.

## Resultado esperado (criterio de "listo")

1. Migración `booking_status_histories` corre limpio.
2. Las 6 Actions cubren su Form Request + Policy + tx + (donde aplica) re-chequeo de disponibilidad.
3. Los tests explícitos del spec §8 + el de concurrencia, todos en verde.
4. UI dashboard y UI pública funcionan end-to-end (creación, cancelación, reprogramación) probadas manualmente en navegador además de los tests automatizados.
5. `vendor/bin/pint --test` y `php artisan test` pasan.
