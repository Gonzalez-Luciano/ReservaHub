<?php

namespace App\Enums;

enum WebhookProcessingStatus: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
