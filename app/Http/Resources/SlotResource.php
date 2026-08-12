<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlotResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'starts_at' => $this->resource['starts_at']->toIso8601String(),
            'ends_at' => $this->resource['ends_at']->toIso8601String(),
        ];
    }
}
