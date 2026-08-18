<?php

namespace App\Services\Payments\Data;

use App\Enums\WebhookProcessingStatus;

final readonly class WebhookProcessingResult
{
    public function __construct(
        public WebhookProcessingStatus $status,
        public ?string $reason = null,
    ) {}
}
