# ReservaHub

Sistema SaaS de reservas para comercios y profesionales. Puede servir para peluquerías, gimnasios, talleres, profesores, estudios o cualquier negocio que trabaje con turnos.

## 1. Objetivo del proyecto

Demostrar que podés construir una aplicación Laravel completa con:

- MVC.
- Autenticación.
- Roles y permisos.
- Multi-tenancy simple.
- Reglas de disponibilidad.
- Prevención de turnos superpuestos.
- API REST.
- Pagos y webhooks.
- Colas.
- Notificaciones.
- Tareas programadas.
- Tiempo real.
- Tests.
- Docker y CI/CD.

## 2. Alcance funcional

### Empresas

- Registro de una empresa.
- Datos comerciales.
- Zona horaria.
- Moneda.
- Configuración de cancelación.
- Logo.
- Estado activo o suspendido.

### Usuarios

Roles sugeridos:

- `owner`: propietario de la empresa.
- `admin`: administra configuración y empleados.
- `employee`: ve y administra sus reservas.
- `customer`: reserva servicios y ve su historial.

Funciones:

- Registro.
- Inicio y cierre de sesión.
- Recuperación de contraseña.
- Verificación de correo.
- Cambio de contraseña.
- Invitación de empleados.
- Activación y desactivación de usuarios.

### Servicios

- Nombre.
- Descripción.
- Duración en minutos.
- Precio.
- Seña requerida.
- Tiempo de preparación entre turnos.
- Estado activo.
- Empleados habilitados.

### Disponibilidad

- Horarios semanales por empleado.
- Pausas.
- Feriados.
- Licencias.
- Bloqueos manuales.
- Duración configurable por servicio.
- Zona horaria de la empresa.

### Reservas

- Crear turno.
- Confirmar turno.
- Reprogramar.
- Cancelar.
- Completar.
- Marcar ausencia.
- Agregar notas internas.
- Registrar origen: web, administración o API.
- Evitar solapamientos.

Estados:

```text
pending
confirmed
cancelled
completed
no_show
```

### Notificaciones

- Confirmación de reserva.
- Reprogramación.
- Cancelación.
- Recordatorio 24 horas antes.
- Recordatorio 2 horas antes.
- Aviso al empleado.

Canales:

- Email.
- Base de datos.
- Opcional: WhatsApp mediante proveedor de prueba o adaptador simulado.

### Pagos

- Crear una intención de pago o preferencia.
- Asociar el pago a una reserva.
- Procesar webhook.
- Evitar webhooks duplicados.
- Registrar payload recibido.
- Confirmar reserva al aprobarse el pago.
- Marcar pago rechazado o pendiente.
- Simular el proveedor durante tests.

### Dashboard

- Reservas de hoy.
- Próximos turnos.
- Cancelaciones.
- Ingresos estimados.
- Servicios más solicitados.
- Empleados con más reservas.

## 3. Modelo de datos

### Tablas principales

#### businesses

```text
id
name
slug
timezone
currency
cancellation_hours
logo_path
is_active
created_at
updated_at
```

#### users

```text
id
business_id nullable
name
email
password
email_verified_at
is_active
created_at
updated_at
```

#### roles y permisos

Podés usar tablas propias o un paquete conocido. Para aprender, conviene comenzar con una columna `role` y después migrar a permisos más granulares.

#### services

```text
id
business_id
name
description
duration_minutes
buffer_minutes
price
deposit_amount
is_active
created_at
updated_at
```

#### employee_service

```text
employee_id
service_id
```

#### schedules

```text
id
employee_id
day_of_week
start_time
end_time
is_active
```

#### schedule_breaks

```text
id
schedule_id
start_time
end_time
```

#### time_offs

```text
id
employee_id
starts_at
ends_at
reason
```

#### bookings

```text
id
business_id
customer_id
employee_id
service_id
starts_at
ends_at
status
price
deposit_amount
notes
source
cancelled_at
created_at
updated_at
```

#### payments

```text
id
booking_id
provider
external_id
status
amount
currency
paid_at
raw_response json
created_at
updated_at
```

#### webhook_events

```text
id
provider
external_event_id
payload json
processed_at
status
error_message
created_at
updated_at
```

### Restricciones e índices

- Índice por `business_id` en todas las tablas multi-tenant.
- Índice compuesto para reservas por `employee_id`, `starts_at`, `ends_at`.
- `external_event_id` único por proveedor.
- Índice por estado y fecha de reserva.
- Foreign keys con políticas de borrado explícitas.

## 4. Arquitectura sugerida

```text
app/
├── Actions/
│   └── Bookings/
│       ├── CreateBooking.php
│       ├── CancelBooking.php
│       ├── ConfirmBooking.php
│       └── RescheduleBooking.php
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   │   ├── Web/
│   │   └── Api/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Listeners/
├── Models/
├── Notifications/
├── Policies/
├── Services/
│   ├── AvailabilityService.php
│   └── Payments/
└── Support/
```

### Responsabilidades

- Controller: coordina request y response.
- Form Request: valida entrada.
- Policy: autoriza.
- Action: ejecuta caso de uso.
- Service: lógica reutilizable o integración.
- Model: relaciones, casts y scopes.
- Job: ejecución asincrónica.
- Event/Listener: efectos secundarios.
- Resource: salida JSON.

## 5. Endpoints API

```text
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/services
GET    /api/employees
GET    /api/availability
POST   /api/bookings
GET    /api/bookings
GET    /api/bookings/{booking}
PATCH  /api/bookings/{booking}
POST   /api/bookings/{booking}/cancel
POST   /api/bookings/{booking}/confirm
POST   /api/payments
POST   /api/webhooks/payments/{provider}
```

### Respuesta estándar

```json
{
  "success": true,
  "data": {},
  "message": "Reserva creada correctamente.",
  "errors": null
}
```

## 6. Reglas de negocio importantes

- Un empleado no puede tener dos reservas superpuestas.
- La duración sale del servicio y no del cliente.
- Una reserva debe estar dentro del horario laboral.
- Debe respetar pausas, feriados y licencias.
- Un cliente no puede cancelar fuera del plazo permitido.
- Un webhook repetido no puede duplicar un pago.
- Una reserva con seña queda pendiente hasta confirmar el pago.
- Toda consulta debe filtrar por empresa.

## 7. Implementación por fases

### Fase 0 — Preparación

1. Crear repositorio.
2. Crear proyecto Laravel.
3. Configurar `.env.example`.
4. Configurar base de datos.
5. Instalar frontend.
6. Configurar formatter.
7. Crear pipeline inicial.

Comandos orientativos:

```bash
composer create-project laravel/laravel reservahub
cd reservahub
php artisan key:generate
php artisan migrate
npm install
npm run build
```

### Fase 1 — Autenticación

1. Instalar starter kit.
2. Registro e inicio de sesión.
3. Verificación de email.
4. Recuperación de contraseña.
5. Tests de autenticación.

### Fase 2 — Empresas y tenancy

1. Crear `businesses`.
2. Asociar usuarios.
3. Middleware para empresa actual.
4. Scope global o consultas explícitas.
5. Policies para evitar acceso cruzado.
6. Tests de aislamiento.

### Fase 3 — Servicios y empleados

1. CRUD de servicios.
2. Asociación empleado-servicio.
3. CRUD de horarios.
4. Pausas y licencias.
5. Seeders de demostración.

### Fase 4 — Motor de disponibilidad

1. Recibir fecha, servicio y empleado.
2. Calcular duración y buffer.
3. Obtener horario laboral.
4. Excluir pausas.
5. Excluir licencias.
6. Excluir reservas existentes.
7. Devolver slots disponibles.
8. Crear tests unitarios.

### Fase 5 — Reservas

1. Crear Form Requests.
2. Crear `CreateBooking` Action.
3. Usar transacción.
4. Volver a validar disponibilidad dentro de la transacción.
5. Crear reserva.
6. Disparar evento.
7. Crear historial.

### Fase 6 — Notificaciones y scheduler

1. Crear notificaciones.
2. Encolar emails.
3. Crear comando para recordatorios.
4. Configurar scheduler.
5. Evitar recordatorios duplicados.
6. Probar con `Notification::fake()`.

### Fase 7 — API y Sanctum

1. Instalar Sanctum.
2. Crear endpoints.
3. Crear Resources.
4. Agregar paginación.
5. Agregar rate limiting.
6. Documentar con Postman u OpenAPI.

### Fase 8 — Pagos

1. Crear contrato `PaymentGateway`.
2. Crear implementación fake.
3. Crear implementación real opcional.
4. Guardar eventos webhook.
5. Validar firma.
6. Implementar idempotencia.
7. Crear job de reconciliación.
8. Tests con payloads simulados.

### Fase 9 — Tiempo real

1. Instalar Reverb.
2. Crear evento de reserva.
3. Canal privado por empresa.
4. Autorizar canal.
5. Actualizar calendario en vivo.

### Fase 10 — Producción

1. Docker.
2. Variables de entorno.
3. Queue worker.
4. Scheduler.
5. Logging.
6. Backups.
7. Deploy.
8. Usuario demo.

## 8. Tests imprescindibles

### Unitarios

- Cálculo de duración.
- Cálculo de slots.
- Regla de cancelación.
- Conversión de zona horaria.

### Feature

- Owner crea servicio.
- Employee no modifica otra empresa.
- Cliente crea reserva válida.
- Reserva fuera de horario falla.
- Reserva solapada falla.
- Reserva durante licencia falla.
- Cancelación tardía falla.
- Webhook repetido no duplica pago.
- Recordatorio se envía una sola vez.

### Concurrencia

Simular dos solicitudes para el mismo turno y verificar que solamente una se confirme.

## 9. CI/CD

Workflow mínimo:

```yaml
name: tests

on:
  push:
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env
      - run: php artisan key:generate
      - run: php artisan test
      - run: vendor/bin/pint --test
```

## 10. Datos demo

Crear seeders para:

- Una empresa demo.
- Un owner.
- Dos empleados.
- Cinco servicios.
- Horarios semanales.
- Clientes.
- Reservas futuras y pasadas.

Usuario sugerido:

```text
owner@reservahub.test
password
```

## 11. Capturas recomendadas

- Login.
- Dashboard.
- Calendario.
- Formulario de servicio.
- Selector de disponibilidad.
- Reserva confirmada.
- Panel de colas.
- Resultado de tests.

## 12. Mejoras opcionales

- Suscripciones por plan.
- Cupones.
- Lista de espera.
- Reservas recurrentes.
- Integración con Google Calendar.
- Exportación iCalendar.
- Facturación.
- Aplicación móvil consumiendo API.
- Métricas con observabilidad.

## 13. Entregables para GitHub

- Código.
- README.
- Diagrama ER.
- Colección Postman.
- Archivo Docker.
- Workflow GitHub Actions.
- Capturas.
- Release `v1.0.0`.
- Issues cerrados que documenten el proceso.
