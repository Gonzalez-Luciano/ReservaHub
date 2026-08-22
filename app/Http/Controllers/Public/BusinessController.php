<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Public/Business/Index', [
            'businesses' => Business::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(Business $business): Response
    {
        return Inertia::render('Public/Business/Show', [
            'business' => $business->only(['id', 'name', 'slug']),
            'services' => Service::where('is_active', true)->orderBy('name')->get(['id', 'name', 'description', 'duration_minutes', 'price']),
        ]);
    }
}
