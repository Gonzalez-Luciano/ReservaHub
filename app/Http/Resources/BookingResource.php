<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $this->business?->timezone ?? config('app.timezone');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->setTimezone($timezone)->toIso8601String(),
            'price' => $this->price,
            'deposit_amount' => $this->deposit_amount,
            'notes' => $this->notes,
            'source' => $this->source,
            'business' => $this->whenLoaded('business', fn () => [
                'id' => $this->business->id,
                'name' => $this->business->name,
                'slug' => $this->business->slug,
                'timezone' => $this->business->timezone,
            ]),
            'service' => ServiceResource::make($this->whenLoaded('service')),
            'employee' => EmployeeResource::make($this->whenLoaded('employee')),
            'customer' => UserResource::make($this->whenLoaded('customer')),
        ];
    }
}
