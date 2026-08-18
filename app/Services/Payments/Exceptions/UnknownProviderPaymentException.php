<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/** El proveedor no conoce ese identificador externo. */
class UnknownProviderPaymentException extends RuntimeException {}
