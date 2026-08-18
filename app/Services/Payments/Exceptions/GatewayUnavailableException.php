<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/** El proveedor no está disponible ahora; reintentable. */
class GatewayUnavailableException extends RuntimeException {}
