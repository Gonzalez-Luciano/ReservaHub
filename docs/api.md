# API de ReservaHub

REST sobre `/api`, autenticada con tokens personales de Laravel Sanctum. Todas las respuestas usan el mismo envelope:

```json
{ "success": true, "data": {}, "message": "", "errors": null }
```

En error, `success` es `false`, `data` es `null` y `errors` trae el detalle de validación cuando lo hay.

## Autenticación

```bash
curl -X POST http://localhost/api/auth/login \
  -H 'Accept: application/json' \
  -d 'email=owner@example.com&password=password&device_name=cli'
```

Devuelve `data.token`. Mandalo en cada petición siguiente:

```bash
curl http://localhost/api/services -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

`POST /api/auth/logout` revoca solo el token usado.

**Cambio de contraseña por API.** `PUT /api/account/password` revoca **todos**
los tokens del usuario, incluido el que hizo la llamada. La respuesta llega con
200 y el mensaje de re-login; cualquier petición posterior con ese token
devuelve 401. Después de cambiarla hay que volver a `POST /api/auth/login`.

## Endpoints

| Método | Ruta | Quién | Qué hace |
|---|---|---|---|
| POST | `/api/auth/login` | público | Emite un token |
| POST | `/api/auth/logout` | autenticado | Revoca el token actual |
| GET | `/api/account` | autenticado | Datos de la cuenta autenticada |
| PATCH | `/api/account/profile` | autenticado | Actualiza nombre y email |
| PUT | `/api/account/password` | autenticado | Cambia la contraseña |
| GET | `/api/services` | staff | Servicios activos del negocio del token |
| GET | `/api/employees` | staff | Empleados activos; `?service_id=` filtra |
| GET | `/api/availability` | staff | Slots libres; `?service_id=&employee_id=&date=YYYY-MM-DD` |
| POST | `/api/bookings` | staff | Crea reserva para un cliente (`customer_email`) |
| POST | `/api/bookings/{id}/confirm` | staff | Confirma una reserva pendiente |
| GET | `/api/business` | staff (owner/admin) | Ajustes del negocio |
| PUT | `/api/business` | staff (owner/admin) | Actualiza los ajustes del negocio |
| PUT | `/api/users/{user}/status` | staff (owner/admin) | Activa o desactiva un usuario |
| GET | `/api/holidays` | staff (owner/admin) | Feriados del negocio |
| POST | `/api/holidays` | staff (owner/admin) | Crea un feriado |
| DELETE | `/api/holidays/{holiday}` | staff (owner/admin) | Elimina un feriado |
| GET | `/api/businesses/{slug}/services` | cliente | Servicios del negocio |
| GET | `/api/businesses/{slug}/employees` | cliente | Empleados del negocio |
| GET | `/api/businesses/{slug}/availability` | cliente | Slots libres |
| POST | `/api/businesses/{slug}/bookings` | cliente | Crea su propia reserva |
| GET | `/api/bookings` | ambos | Listado paginado; filtros `status`, `from`, `to`, `employee_id`, `per_page` |
| GET | `/api/bookings/{id}` | ambos | Detalle |
| PATCH | `/api/bookings/{id}` | ambos | Reprograma (`starts_at`) |
| POST | `/api/bookings/{id}/cancel` | ambos | Cancela |

Staff ve las reservas de su negocio; un cliente ve solo las propias, de cualquier negocio.

### Pagos

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/bookings/{booking}/payments` | Inicia el pago de la seña. `201` con el intento nuevo; `200` si ya había uno en curso (devuelve el mismo). |
| `GET` | `/api/bookings/{booking}/payments` | Lista los intentos de pago de la reserva. |
| `GET` | `/api/bookings/{booking}/payments/{payment}` | Detalle de un intento. |
| `POST` | `/api/webhooks/payments/{provider}` | Endpoint del proveedor. Sin autenticación de usuario: se valida por firma HMAC. |

Campos de un pago: `id`, `status` (`pending`/`approved`/`rejected`/`expired`), `amount`, `currency`,
`expires_at`, `paid_at`, `application_outcome`, `failure_reason`, `created_at` y `checkout_url`
(presente solo mientras el intento está `pending` y la ventana de pago sigue vigente).

Errores de iniciación (`422`): la reserva no requiere seña, no está pendiente, o su ventana de pago
venció. `403`/`404` por autorización y aislamiento entre negocios, igual que en reservas.

El webhook responde `200` cuando procesa, registra o descarta el evento; `401` con firma inválida o
fuera de tolerancia; `404` con un proveedor desconocido; `422` con un cuerpo ilegible; y `500` cuando
el fallo es reintentable (por ejemplo, un pago externo desconocido), para que el proveedor reintente.

## Paginación

`GET /api/bookings` devuelve `data.items` y `data.meta` (`current_page`, `per_page`, `total`, `last_page`). `per_page` por defecto 15, máximo 100.

## Límites de tasa

60 peticiones por minuto por usuario. `POST /api/auth/login`, 5 por minuto por email + IP. Al excederlos, 429 con el envelope.

## Códigos de error

| Código | Cuándo |
|---|---|
| 401 | Sin token, token inválido, credenciales incorrectas, cuenta o negocio inactivo |
| 403 | El rol no puede realizar la acción (incluye cancelar fuera del plazo) |
| 404 | Recurso inexistente o de otro negocio |
| 422 | Validación o regla de negocio (horario ocupado, fuera de horario laboral, estado inválido) |
| 429 | Límite de tasa superado |

Un feriado (`business_holidays`) de otro negocio devuelve **404**, no 403: el
scope de negocio filtra la consulta antes de que el recurso se resuelva, así
que la API no confirma su existencia. Esto no aplica a `PUT
/api/users/{user}/status`: el modelo `User` no lleva ese scope (`business_id`
es nullable, no toda fila pertenece a un negocio), así que un usuario de otro
negocio sí se resuelve por route-model binding y la Policy lo rechaza con 403.

En esta fase, `PUT /api/users/{user}/status` es la única forma de cambiar el
`is_active` de un `admin` o un `owner`: el panel web solo expone el toggle de
estado para empleados.

## Ejemplo completo

```bash
TOKEN=$(curl -s -X POST http://localhost/api/auth/login \
  -H 'Accept: application/json' \
  -d 'email=cliente@example.com&password=password&device_name=cli' | jq -r '.data.token')

curl -s "http://localhost/api/businesses/barberia-juan/availability?service_id=1&employee_id=2&date=2026-09-07" \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -s -X POST http://localhost/api/businesses/barberia-juan/bookings \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" \
  -d 'service_id=1&employee_id=2&starts_at=2026-09-07T09:00:00-03:00'
```

## OpenAPI

Con la app corriendo en local, la especificación navegable está en `http://localhost/docs/api` y el JSON en `http://localhost/docs/api.json`, generados por `dedoc/scramble` a partir de las rutas, los Form Requests y los Resources.
