<?php

namespace App\Actions\Services;

use App\Models\Service;

class UpdateService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Service $service, array $data): Service
    {
        $service->update($data);

        return $service;
    }
}
