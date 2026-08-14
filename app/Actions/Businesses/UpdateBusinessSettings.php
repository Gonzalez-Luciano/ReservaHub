<?php

namespace App\Actions\Businesses;

use App\Models\Business;

class UpdateBusinessSettings
{
    /**
     * Asigna campo por campo a propósito: `slug`, `logo_path` e `is_active` son
     * fillable en el modelo y un `update($data)` masivo dejaría que se colaran
     * desde el request.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Business $business, array $data): Business
    {
        $business->update([
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'currency' => $data['currency'],
            'cancellation_hours' => $data['cancellation_hours'],
        ]);

        return $business;
    }
}
