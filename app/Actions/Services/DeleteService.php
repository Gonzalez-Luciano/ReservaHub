<?php

namespace App\Actions\Services;

use App\Models\Service;

class DeleteService
{
    public function handle(Service $service): void
    {
        $service->delete();
    }
}
