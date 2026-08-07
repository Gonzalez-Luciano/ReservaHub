# Fase 3 — Servicios y empleados

## Objetivo

Agregar servicios, empleados (alta vía invitación por email), asociación empleado-servicio, horarios semanales con pausas, licencias y seeders de demo. Solo owner/admin gestiona todo (sin self-service de empleado en esta fase). Incluye backend completo + UI Inertia básica (listado + form, sin diseño pulido).

## Alcance

- CRUD de `services` (owner/admin).
- Alta de empleados vía invitación por email con token (empleado acepta y setea su password).
- Asociación empleado-servicio (pivot, attach/detach).
- CRUD de `schedules` (horario semanal por empleado) + `schedule_breaks` (pausas dentro de un schedule).
- CRUD de `time_offs` (licencias por empleado).
- Seeders de demostración (subset de la sección 10 del spec: empresa+owner, 2 empleados, 5 servicios, horarios semanales, asignación empleado-servicio — sin clientes/bookings, que no existen todavía).

## Fuera de alcance (para no mezclar fases)

- Motor de disponibilidad (combinar schedules/breaks/time_offs/bookings en slots) → Fase 4.
- Reservas → Fase 5.
- Self-service de empleado (ver/gestionar su propio horario o licencias) → futuro, no en Fase 3.
- API REST (`/api/*`) → Fase 7. Los controllers de esta fase devuelven respuestas Inertia, no JSON.

## Decisiones

| Decisión | Elegido | Alternativa descartada | Por qué |
|---|---|---|---|
| Alta de empleados | Invitación por email con token; se crea `User` recién al aceptar | Alta directa por owner/admin sin invite | Más fiel a un SaaS real; el empleado define su propia password |
| Modelo de invitación | Tabla `employee_invitations` propia (no reusar `users` con password nullable) | Crear `User` inactivo de entrada y completarlo al aceptar | `users.password` es NOT NULL (migración base); evita filas de usuario a medias |
| `employee_id` en schedules/time_offs/employee_service | Apunta a `users.id` (empleado = User con role=employee) | Tabla `employees` separada | Sigue el patrón ya sentado en Fase 2 (roles como columna en `users`) |
| Scoping tenant en tablas nuevas | `services`, `schedules`, `time_offs`, `employee_invitations` llevan `business_id` propio + trait `BelongsToBusiness` | Derivar siempre business_id vía relación (employee→business) | Consistente con el global scope ya construido en Fase 2; evita joins para filtrar por empresa |
| `schedule_breaks` | Sin `business_id` propio, se autoriza vía `schedule->business_id` | `business_id` propio + trait | Es hijo directo de `schedule`, no se consulta de forma independiente |
| `employee_service` (pivot) | Sin `business_id` propio; se valida `employee.business_id === service.business_id` al hacer `sync()`/`attach()` | `business_id` propio en el pivot | Ambos lados del pivot ya están scopeados; la validación en la Action alcanza |
| Un schedule por día | `unique(employee_id, day_of_week)` — un bloque de horario por día | Múltiples bloques por día | Simplifica; un turno partido se modela como pausa (`schedule_breaks`) dentro del único bloque del día |
| Self-service empleado | No incluido en Fase 3 | Employee ve/gestiona su propio horario y licencias | Mantiene la fase acotada; no hay dashboard de empleado todavía |
| Seeder de empleados demo | Se crean directo (factory), no vía flujo de invitación | Seedear invitaciones y "aceptarlas" en código | Evita depender de mail/tokens en seeding |

## Modelo de datos

```
services
  id, business_id, name, description, duration_minutes,
  buffer_minutes, price, deposit_amount, is_active, timestamps
  → BelongsToBusiness trait + global scope

employee_service (pivot)
  employee_id (FK users), service_id (FK services), timestamps
  unique(employee_id, service_id)
  → sin business_id propio; validado en la Action al attach

schedules
  id, business_id, employee_id (FK users), day_of_week (tinyint 0-6),
  start_time, end_time, is_active, timestamps
  unique(employee_id, day_of_week)
  → BelongsToBusiness trait + global scope

schedule_breaks
  id, schedule_id (FK schedules), start_time, end_time, timestamps
  → sin business_id propio, scope vía schedule->business_id

time_offs
  id, business_id, employee_id (FK users), starts_at, ends_at, reason nullable, timestamps
  → BelongsToBusiness trait + global scope

employee_invitations
  id, business_id, email, token (unique), invited_by (FK users),
  expires_at, accepted_at nullable, timestamps
  → BelongsToBusiness trait
```

`day_of_week`: entero 0(domingo)-6(sábado); helpers en `App\Enums\DayOfWeek` (mismo patrón que `App\Enums\Role`).

## Flujo de invitación de empleados

1. Owner/admin invita (form: email, nombre opcional) → crea `employee_invitations` (token random 40 chars, `expires_at` = +7 días).
2. Se envía email con signed URL de aceptación (`temporarySignedRoute`, verificado además contra `expires_at` de la tabla, no solo la firma de la URL).
3. Ruta pública `GET/POST /invitations/{token}/accept`: valida token no vencido/no aceptado → transacción: crea `User(role=employee, business_id, email_verified_at=now)` + marca `accepted_at`. Si falla cualquiera de los dos pasos, no queda nada a medias.
4. Reenviar invitación pendiente regenera token/expires_at sobre la misma fila (no duplica).
5. `EmployeeInvitationPolicy`: solo owner/admin de la empresa listan/crean/revocan invitaciones de su empresa.

## Policies, actions y validaciones

- `ServicePolicy`: owner/admin CRUD completo; employee ve listado de solo lectura (servicios activos de su empresa).
- `SchedulePolicy`: cubre schedules + breaks (breaks se autorizan vía `schedule->business_id`, sin policy propia). Solo owner/admin.
- `TimeOffPolicy`: análoga a `SchedulePolicy`. Solo owner/admin.
- Actions (una clase por caso de uso, Form Request valida antes):
  - `App\Actions\Services\{CreateService,UpdateService,DeleteService}`
  - `App\Actions\Employees\{InviteEmployee,ResendInvitation,AcceptInvitation}`
  - `App\Actions\Schedules\{CreateSchedule,AddScheduleBreak,CreateTimeOff}`
- Validaciones: `end_time > start_time` en schedules y breaks; break debe caer dentro del rango del schedule padre; `time_off.ends_at > starts_at`; overlap de schedule por día ya lo bloquea el unique constraint, pero se valida también en el Form Request para devolver un error legible en vez de un 500.

## UI (Inertia/React)

Bajo `resources/js/Pages/Dashboard/`, protegidas por el middleware `business` existente, solo owner/admin:

- `Services/Index.jsx` + `Services/Form.jsx` (create/edit compartido).
- `Employees/Index.jsx` (listado + invitar) + `Employees/InviteForm.jsx`.
- `Schedules/Index.jsx` por empleado (semana con horarios + pausas inline) + sección de `TimeOffs` (lista + form).
- `resources/js/Pages/Invitations/Accept.jsx` (pública, fuera del layout de dashboard).

Controllers REST estándar (`ServiceController`, `EmployeeInvitationController`, `ScheduleController`, `TimeOffController`) devuelven respuestas Inertia.

## Seeders de demostración

`BusinessSeeder` (empresa + owner, reusa el patrón de registro de Fase 2) + 2 empleados creados directo vía factory (sin pasar por invitación) + 5 servicios + horarios semanales por empleado + asignación empleado-servicio vía `sync()`.

## Testing

TDD por pieza, mismo patrón que Fase 2:

- Invitación: crear, aceptar, token expirado, token ya aceptado, token inválido; rollback transaccional si falla la creación del User.
- CRUD servicios: incl. caso cross-business (403).
- CRUD schedules + breaks: incl. overlap de horario rechazado, break fuera de rango rechazado.
- CRUD time_offs: incl. cross-business (403).
- Attach/detach empleado-servicio: incl. intento de mezclar empleado y servicio de empresas distintas (rechazado).
- Caso explícito del spec ("employee no modifica otra empresa") repetido para Service/Schedule/TimeOff.
- Unit test de `DayOfWeek` / lógica de validación de solapamiento si no es trivial.

## Resultado esperado (criterio de "listo")

1. Migraciones corren limpio: `services`, `employee_service`, `schedules`, `schedule_breaks`, `time_offs`, `employee_invitations`.
2. Invitación de empleado funciona end-to-end (invitar → email → aceptar → User creado con role=employee).
3. Las 4 policies (`Service`, `Schedule`, `TimeOff`, `EmployeeInvitation`) bloquean acceso cruzado entre empresas (403).
4. Asociación empleado-servicio rechaza mezclar empresas distintas.
5. Seeder de demo corre sin error y deja datos consistentes (empresa, owner, 2 empleados, 5 servicios, horarios, asignaciones).
6. `vendor/bin/pint --test` y `php artisan test` pasan.
