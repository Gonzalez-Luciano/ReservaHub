<?php

namespace Tests\Unit\Models;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_null_when_unbound(): void
    {
        $this->assertNull(Business::current());
    }

    public function test_current_returns_bound_business(): void
    {
        $business = Business::factory()->create();
        app()->instance(Business::class, $business);

        $this->assertTrue(Business::current()->is($business));
    }

    public function test_factory_generates_unique_slug(): void
    {
        $a = Business::factory()->create(['name' => 'Peluquería Norte']);
        $b = Business::factory()->create(['name' => 'Peluquería Norte']);

        $this->assertNotSame($a->slug, $b->slug);
    }
}
