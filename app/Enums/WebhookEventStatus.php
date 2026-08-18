<?php

namespace App\Enums;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    /** `received` y `failed` son reprocesables: nada garantiza que terminaran. */
    public function isReprocessable(): bool
    {
        return $this === self::Received || $this === self::Failed;
    }
}
