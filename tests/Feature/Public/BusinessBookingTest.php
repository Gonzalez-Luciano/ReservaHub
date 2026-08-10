<?php

namespace Tests\Feature\Public;

use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessBookingTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_shows_the_public_business_page_by_slug(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        Service::factory()->for($business)->create(['is_active' => true]);

        $this->get('/negocios/barberia-juan')
            ->assertInertia(fn ($page) => $page->component('Public/Business/Show')->has('services', 1));
    }

    public function test_guest_is_redirected_to_login_when_trying_to_book(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);

        $this->post('/negocios/barberia-juan/reservar', [])->assertRedirect('/login');
    }

    public function test_customer_creates_a_booking_through_the_public_flow(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan', 'timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0, 'is_active' => true]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $service->employees()->attach($employee->id);

        $response = $this->actingAs($customer)->post('/negocios/barberia-juan/reservar', [
            'service_id' => $service->id,
            'employee_id' => $employee->id,
            'starts_at' => $date->setTime(9, 0)->toIso8601String(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('bookings', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'source' => 'web',
        ]);
    }

    public function test_staff_user_cannot_book_through_the_public_customer_flow(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        $staff = User::factory()->employee()->create();

        $this->actingAs($staff)->post('/negocios/barberia-juan/reservar', [])->assertForbidden();
    }

    public function test_create_page_does_not_500_on_malformed_query_params(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan']);
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get('/negocios/barberia-juan/reservar?service_id=abc&employee_id=abc&date=not-a-date')
            ->assertOk();
    }

    public function test_public_business_page_only_shows_its_own_services(): void
    {
        $businessA = Business::factory()->create(['slug' => 'barberia-juan']);
        $businessB = Business::factory()->create(['slug' => 'otra-barberia']);
        Service::factory()->for($businessA)->create(['is_active' => true, 'name' => 'Corte A']);
        Service::factory()->for($businessB)->create(['is_active' => true, 'name' => 'Corte B']);

        $this->get('/negocios/barberia-juan')
            ->assertInertia(fn ($page) => $page->component('Public/Business/Show')
                ->has('services', 1)
                ->where('services.0.name', 'Corte A')
            );
    }

    public function test_customer_cannot_book_a_service_belonging_to_another_business(): void
    {
        $businessA = Business::factory()->create(['slug' => 'barberia-juan', 'timezone' => 'UTC']);
        $businessB = Business::factory()->create(['slug' => 'otra-barberia', 'timezone' => 'UTC']);
        $employeeA = User::factory()->employee()->create(['business_id' => $businessA->id]);
        $serviceB = Service::factory()->for($businessB)->create(['duration_minutes' => 30, 'buffer_minutes' => 0, 'is_active' => true]);
        $customer = User::factory()->customer()->create();
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $businessA->id,
            'employee_id' => $employeeA->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $response = $this->actingAs($customer)->post('/negocios/barberia-juan/reservar', [
            'service_id' => $serviceB->id,
            'employee_id' => $employeeA->id,
            'starts_at' => $date->setTime(9, 0)->toIso8601String(),
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('bookings', [
            'business_id' => $businessA->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_inactive_business_public_page_returns_not_found(): void
    {
        Business::factory()->create(['slug' => 'barberia-juan', 'is_active' => false]);

        $this->get('/negocios/barberia-juan')->assertNotFound();
    }

    public function test_inactive_business_booking_routes_return_not_found(): void
    {
        Business::factory()->create(['slug' => 'barberia-juan', 'is_active' => false]);
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)->get('/negocios/barberia-juan/reservar')->assertNotFound();
        $this->actingAs($customer)->post('/negocios/barberia-juan/reservar', [])->assertNotFound();
    }
}
