<?php

namespace App\Exceptions;

use RuntimeException;

class MissingBusinessContextException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'No business is bound to the container while querying [%s]. '.
            'Bind a Business via app()->instance(Business::class, $business) before querying tenant-owned models, '.
            'or explicitly opt into an unscoped query with withoutGlobalScope(\App\Models\Scopes\BusinessScope::class).',
            $modelClass
        ));
    }
}
