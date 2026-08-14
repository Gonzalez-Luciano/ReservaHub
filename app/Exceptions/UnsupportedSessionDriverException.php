<?php

namespace App\Exceptions;

use RuntimeException;

class UnsupportedSessionDriverException extends RuntimeException
{
    public static function for(string $driver): self
    {
        return new self(
            "La revocación de acceso requiere SESSION_DRIVER=database; el driver configurado es [{$driver}]."
        );
    }
}
