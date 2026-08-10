<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingsTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_employee_creates_a_manual_booking_for_an_existing_customer(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create(['email' => 'cliente@example.com']);
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

        $response = $this->actingAs($staff)->post('/dashboard/bookings', [
            'customer_email' => 'cliente@example.com',
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0)->toIso8601String(),
            'notes' => 'Cliente pidió turno por teléfono',
        ]);

        $response->assertRedirect('/dashboard/bookings');
        $this->assertDatabaseHas('bookings', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'source' => 'admin',
            'notes' => 'Cliente pidió turno por teléfono',
        ]);
    }

    public function test_employee_of_another_business_cannot_view_or_act_on_a_booking(): void
    {
        // Booking is tenant-scoped (BelongsToBusiness), and implicit route-model
        // binding runs through that global scope, so a booking belonging to a
        // different business than the one bound into the container is simply
        // invisible to the query — it 404s before the Policy ever runs. This
        // matches the established convention for cross-business resource access
        // elsewhere in the dashboard (see ServicesTest/SchedulesTest/TimeOffsTest).
        $outsider = User::factory()->employee()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        $this->actingAs($outsider)->get("/dashboard/bookings/{$booking->id}")->assertNotFound();
        $this->actingAs($outsider)->post("/dashboard/bookings/{$booking->id}/confirm")->assertNotFound();
    }

    public function test_staff_confirms_cancels_completes_and_marks_no_show(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $pending = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$pending->id}/confirm")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $pending->id, 'status' => 'confirmed']);

        $confirmed = Booking::factory()->confirmed()->create(['business_id' => $business->id]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$confirmed->id}/complete")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $confirmed->id, 'status' => 'completed']);

        $forNoShow = Booking::factory()->confirmed()->create(['business_id' => $business->id]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$forNoShow->id}/no-show")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $forNoShow->id, 'status' => 'no_show']);

        $toCancel = Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);
        $this->actingAs($staff)->post("/dashboard/bookings/{$toCancel->id}/cancel")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $toCancel->id, 'status' => 'cancelled']);
    }

    public function test_index_lists_only_bookings_of_the_current_business(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        Booking::factory()->count(2)->create(['business_id' => $business->id]);
        Booking::factory()->create();

        // Task 9 (a later task in this plan) is what creates the actual
        // resources/js/Pages/Dashboard/Bookings/Index.jsx file; passing
        // `shouldExist: false` here asserts the server-side component-name
        // contract without depending on that not-yet-built frontend page.
        $this->actingAs($staff)->get('/dashboard/bookings')
            ->assertInertia(fn ($page) => $page->component('Dashboard/Bookings/Index', false)->has('bookings', 2));
    }

    public function test_create_page_renders_the_booking_form(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->actingAs($staff)->get('/dashboard/bookings/create')
            ->assertInertia(fn ($page) => $page->component('Dashboard/Bookings/Form'));
    }

    public function test_staff_reschedules_a_booking(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = $this->nextMonday();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $newStart = $date->setTime(10, 0);

        $this->actingAs($staff)
            ->put("/dashboard/bookings/{$booking->id}/reschedule", [
                'starts_at' => $newStart->toIso8601String(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'starts_at' => $newStart->setTimezone('UTC')->format('Y-m-d H:i:s'),
        ]);
    }
}
