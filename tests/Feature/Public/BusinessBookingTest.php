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

        // Task 11 (a later task in this plan) is what creates the actual
        // resources/js/Pages/Public/Business/Show.jsx file; passing
        // `shouldExist: false` here asserts the server-side component-name
        // contract without depending on that not-yet-built frontend page.
        $this->get('/negocios/barberia-juan')
            ->assertInertia(fn ($page) => $page->component('Public/Business/Show', false)->has('services', 1));
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
}
