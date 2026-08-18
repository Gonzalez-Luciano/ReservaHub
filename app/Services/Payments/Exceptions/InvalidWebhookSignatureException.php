<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

class InvalidWebhookSignatureException extends RuntimeException {}
