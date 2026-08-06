<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Business $business): Response
    {
        return Inertia::render('Dashboard/Index', [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
            ],
        ]);
    }
}
