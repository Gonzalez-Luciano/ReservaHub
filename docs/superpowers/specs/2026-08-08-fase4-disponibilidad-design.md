# Fase 4 — Motor de disponibilidad

## Objetivo

Calcular los slots disponibles para reservar un servicio con un empleado en una fecha dada, combinando horario semanal, pausas, licencias y reservas existentes, con buffer entre turnos y respetando la zona horaria del negocio. Lógica pura en `App\Services\AvailabilityService`, testeada por unit tests, sin HTTP.

## Alcance

- Tabla + modelo `bookings` (prerequisito: el motor necesita "excluir reservas existentes"), incluyendo `App\Enums\BookingStatus`.
- `App\Services\AvailabilityService::getAvailableSlots()`.
- `BookingFactory` para poblar reservas en tests.
- Tests unitarios exhaustivos del algoritmo (cálculo de slots, timezone, buffer, exclusión de pausas/licencias/bookings).

## Fuera de alcance (para no mezclar fases)

- `CreateBooking` Action, Form Requests, Policy de bookings, transacción de creación con re-chequeo de disponibilidad → Fase 5.
- Endpoint `GET /api/availability` → Fase 7 (API + Sanctum).
- UI de selector de disponibilidad → se agrega junto con la UI de reservas en Fase 5.
- Notificaciones/recordatorios → Fase 6.

## Decisiones

| Decisión | Elegido | Alternativa descartada | Por qué |
|---|---|---|---|
| Firma del método | `getAvailableSlots(Business $business, Service $service, User $employee, CarbonImmutable $date)` — `employee` obligatorio | `employee` opcional, agregando slots de todos los empleados habilitados | Cubre literalmente lo que pide el spec Fase 4; búsqueda multi-empleado es un caso de uso posterior, no bloquea nada |
| Granularidad de slots | Paso fijo = `service.duration_minutes` | Paso fijo configurable (ej. 15 min) | El spec no define un campo de configuración para esto; usar la duración del servicio evita inventar un requisito nuevo |
| Semántica de `buffer_minutes` | Cada reserva (existente o candidata) ocupa `[starts_at, ends_at + buffer_minutes)` — buffer solo "después" | Buffer antes y después | Modela "tiempo de preparación para el próximo turno" tal como lo describe el spec (§2 Servicios) |
| Buffer vs. breaks/time_offs | El buffer de un candidato solo se compara contra bookings existentes, no contra pausas/licencias (esas solo acotan la ventana laboral) | Buffer también debe respetar breaks/time_offs | Evita complejidad no pedida por el spec; breaks/time_offs son límites duros de horario, no "citas" con buffer propio |
| Slots pasados (fecha = hoy) | Se excluyen candidatos con `start` anterior a `now()` en tz del negocio | El servicio no conoce "ahora", es cálculo puro | El spec Fase 5 no vuelve a mencionar este filtro; sin él, un usuario podría ver/reservar turnos ya vencidos el mismo día |
| Statuses que bloquean | `pending` y `confirmed` ocupan el slot; `cancelled` y `no_show` lo liberan; `completed` se trata igual que `pending`/`confirmed` por consistencia aunque en la práctica ya es pasado | Todo excepto `cancelled` bloquea | Un turno cancelado o no-show libera el horario para otra reserva; es la semántica esperada de un sistema de turnos |
| `employee_id` en bookings | Apunta a `users.id` (mismo patrón que schedules/time_offs de Fase 3) | Tabla `employees` separada | Consistencia con el resto del dominio |
| FKs de `bookings` | `cascadeOnDelete` en `business_id`, `customer_id`, `employee_id`, `service_id` | `restrictOnDelete`/`nullOnDelete` en `service_id` para preservar histórico | Sigue la convención ya usada en todas las migraciones del proyecto (services, schedules, time_offs); no se introduce un patrón nuevo en esta fase |
| Alcance de `bookings` en esta fase | Solo migración + modelo + factory, sin Actions/Policies/Controllers | Adelantar también `CreateBooking` | Mantiene la fase acotada al motor de disponibilidad; Fase 5 es la que da de alta reservas reales |

## Modelo de datos

```
bookings
  id, business_id (FK businesses, cascade), customer_id (FK users, cascade),
  employee_id (FK users, cascade), service_id (FK services, cascade),
  starts_at, ends_at, status (string, BookingStatus),
  price decimal(10,2), deposit_amount decimal(10,2) nullable,
  notes text nullable, source string (web|admin|api),
  cancelled_at nullable, timestamps

  index: business_id
  index compuesto: employee_id + starts_at + ends_at
  index: status + starts_at
  → BelongsToBusiness trait + global scope
```

`App\Enums\BookingStatus` (mismo patrón que `DayOfWeek`): `Pending, Confirmed, Cancelled, Completed, NoShow`.

`App\Models\Booking`: relaciones `business()`, `customer()` (→ `User`), `employee()` (→ `User`), `service()`; casts `starts_at`/`ends_at` → `datetime`, `status` → enum.

## Algoritmo (`AvailabilityService::getAvailableSlots`)

1. Determinar `day_of_week` de `$date` → buscar `Schedule` activo de `$employee` para ese día. Si no hay → `[]`.
2. Construir ventana laboral: `$date` + `schedule->start_time`..`end_time`, en `$business->timezone`.
3. Restar `schedule_breaks` del schedule → sub-intervalos libres.
4. Restar `time_offs` del empleado que se solapen con `$date` → más sub-intervalos libres.
5. Dentro de cada sub-intervalo libre, generar candidatos cada `service->duration_minutes`, empezando en el inicio del sub-intervalo, mientras `start + duration <= sub-intervalo.end`.
6. Cargar `bookings` del empleado con status `pending`/`confirmed` que se solapen con la ventana laboral (con su `service` para el buffer). Cada uno define un span ocupado `[starts_at, ends_at + service.buffer_minutes)`.
7. Cada candidato define su propio span `[start, start + duration + service.buffer_minutes)` (el `service` del candidato es el solicitado, no el del booking existente). Se descarta si su span se solapa con algún span ocupado (`startA < endB && startB < endA`).
8. Si `$date` es hoy: descartar candidatos con `start < now()` en tz del negocio.
9. Slots válidos restantes → `{starts_at: start, ends_at: start + duration}` (sin buffer, el buffer no es parte visible del turno), en tz del negocio.

## Testing

Unit tests en `tests/Unit/Services/AvailabilityServiceTest.php`, sin HTTP:

- Slots básicos: schedule sin breaks/bookings → slots esperados cada `duration_minutes`.
- Excluye una pausa (`schedule_breaks`) en medio del horario.
- Excluye una licencia (`time_offs`) que cubre todo el día o parte de él.
- Un booking existente bloquea su propio horario y, por buffer, el slot siguiente.
- Booking `cancelled`/`no_show` no bloquea; `pending`/`confirmed`/`completed` sí.
- Sin `Schedule` activo ese día de la semana → `[]`.
- Zona horaria: negocio con tz ≠ UTC calcula slots correctos (incluye caso donde el día local cruza medianoche UTC).
- Fecha = hoy: candidatos con `start` ya pasado quedan afuera; otra fecha no aplica ese filtro.
- `buffer_minutes = 0` → slots consecutivos sin gap.

`BookingFactory` nuevo (states para cada status) para poblar reservas de prueba.

## Resultado esperado (criterio de "listo")

1. Migración `bookings` corre limpio, con los índices del spec §3.
2. `AvailabilityService::getAvailableSlots()` cubre los 9 casos de test listados arriba, todos en verde.
3. `vendor/bin/pint --test` y `php artisan test` pasan.
4. Sin Actions/Policies/Controllers/rutas de bookings todavía (queda para Fase 5).
