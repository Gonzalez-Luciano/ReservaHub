<?php

namespace App\Actions\Services;

use App\Models\Service;

class CreateService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Service
    {
        return Service::create($data);
    }
}
