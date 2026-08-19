<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/**
 * `PAYMENTS_SIMULATED_WEBHOOK_SECRET` no está configurado. Falla cerrado a
 * propósito: sin secreto, firmar y verificar usarían la misma clave HMAC
 * vacía, y el endpoint de webhook aceptaría cualquier firma forjada por
 * quien sepa que la clave está vacía. No hay binding parcial ni degradado.
 */
class MissingWebhookSecretException extends RuntimeException {}
