<?php

namespace Tests\Feature\Public;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class BusinessIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_active_businesses_ordered_by_name(): void
    {
        Business::factory()->create(['name' => 'Zapatería Zoe', 'slug' => 'zapateria-zoe']);
        Business::factory()->create(['name' => 'Barbería Ana', 'slug' => 'barberia-ana']);

        $this->get('/negocios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Business/Index')
                ->has('businesses', 2)
                ->where('businesses.0.name', 'Barbería Ana')
                ->where('businesses.0.slug', 'barberia-ana')
                ->where('businesses.1.name', 'Zapatería Zoe'));
    }

    public function test_an_inactive_business_is_not_listed(): void
    {
        Business::factory()->create(['name' => 'Negocio Activo', 'is_active' => true]);
        Business::factory()->create(['name' => 'Negocio Inactivo', 'is_active' => false]);

        $this->get('/negocios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('businesses', 1)
                ->where('businesses.0.name', 'Negocio Activo'));
    }

    public function test_the_listing_does_not_leak_business_settings(): void
    {
        Business::factory()->create(['name' => 'Negocio Único', 'timezone' => 'America/Argentina/Buenos_Aires']);

        $this->get('/negocios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('businesses.0', fn (Collection $business) => $business->keys()->all() === ['id', 'name', 'slug']));
    }
}
