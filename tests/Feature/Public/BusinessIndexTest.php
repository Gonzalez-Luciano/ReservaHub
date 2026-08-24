<?php

namespace Tests\Feature\Public;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
                ->where(
                    'businesses.0',
                    fn (Collection $business) => $business->keys()->sort()->values()->all()
                        === ['currency', 'id', 'lowest_price', 'name', 'services_count', 'slug']
                ));
    }

    public function test_the_listing_exposes_the_active_service_count_and_lowest_price(): void
    {
        $business = Business::factory()->create(['name' => 'Negocio Único']);
        Service::factory()->for($business)->create(['is_active' => true, 'price' => 100]);
        Service::factory()->for($business)->create(['is_active' => true, 'price' => 50]);

        $this->get('/negocios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('businesses.0.services_count', 2)
                ->where('businesses.0.lowest_price', fn ($price) => (float) $price === 50.0));
    }

    public function test_inactive_services_are_excluded_from_the_count(): void
    {
        $business = Business::factory()->create(['name' => 'Negocio Único']);
        Service::factory()->for($business)->create(['is_active' => true, 'price' => 80]);
        Service::factory()->for($business)->create(['is_active' => false, 'price' => 10]);

        $this->get('/negocios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('businesses.0.services_count', 1)
                ->where('businesses.0.lowest_price', fn ($price) => (float) $price === 80.0));
    }

    public function test_an_inactive_business_is_still_absent(): void
    {
        // Invariante de la Fase 10.5: agregar servicios activos y baratos a
        // un negocio inactivo no debe hacerlo aparecer por la puerta de
        // atrás de la agregación.
        $inactive = Business::factory()->create(['name' => 'Negocio Inactivo', 'is_active' => false]);
        Service::factory()->for($inactive)->create(['is_active' => true, 'price' => 1]);

        Business::factory()->create(['name' => 'Negocio Activo', 'is_active' => true]);

        $this->get('/negocios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('businesses', 1)
                ->where('businesses.0.name', 'Negocio Activo'));
    }

    public function test_the_listing_query_count_does_not_grow_with_more_businesses(): void
    {
        Business::factory()->count(2)->create()->each(function (Business $business) {
            Service::factory()->for($business)->count(2)->create(['is_active' => true]);
        });

        DB::enableQueryLog();
        $this->get('/negocios')->assertOk();
        $queriesForTwoBusinesses = count(DB::getQueryLog());
        DB::flushQueryLog();

        Business::factory()->count(8)->create()->each(function (Business $business) {
            Service::factory()->for($business)->count(2)->create(['is_active' => true]);
        });
        DB::flushQueryLog(); // descarta las consultas del setup, no las que queremos medir.

        $this->get('/negocios')->assertOk();
        $queriesForTenBusinesses = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queriesForTwoBusinesses,
            $queriesForTenBusinesses,
            'Se esperaba que el número de consultas no creciera con la cantidad de negocios (N+1).'
        );
    }
}
