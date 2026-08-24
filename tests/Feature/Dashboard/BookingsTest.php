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

    public function test_the_form_only_offers_employees_assigned_to_the_service(): void
    {
        // Sin esto, CreateBooking:45 rechaza la combinación recién al guardar
        // con "Ese empleado no realiza este servicio".
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();
        $assignedEmployee = User::factory()->employee()->create(['business_id' => $business->id]);
        User::factory()->employee()->create(['business_id' => $business->id]);
        $service->employees()->attach($assignedEmployee->id);

        $this->actingAs($staff)
            ->get("/dashboard/bookings/create?service_id={$service->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Form')
                ->has('employees', 1)
                ->where('employees.0.id', $assignedEmployee->id)
            );
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

    public function test_create_page_does_not_500_on_malformed_query_params(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->actingAs($staff)
            ->get('/dashboard/bookings/create?employee_id=abc&service_id=abc&date=not-a-date')
            ->assertOk();
    }

    public function test_create_page_does_not_500_on_a_non_string_date_query_param(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->actingAs($staff)
            ->get('/dashboard/bookings/create?employee_id=1&service_id=1&date[]=x')
            ->assertOk();
    }

    public function test_reschedule_slots_endpoint_returns_available_slots_excluding_the_booking_itself(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $date = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->confirmed()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $response = $this->actingAs($staff)
            ->get("/dashboard/bookings/{$booking->id}/reschedule-slots?date={$date->format('Y-m-d')}")
            ->assertOk()
            ->json();

        $starts = array_column($response['slots'], 'starts_at');
        $this->assertCount(2, $response['slots']);
        $this->assertStringContainsString('09:00', $starts[0]);
    }

    public function test_reschedule_slots_endpoint_requires_reschedule_authorization(): void
    {
        // Same convention as test_employee_of_another_business_cannot_view_or_act_on_a_booking:
        // a booking belonging to a different business than the one bound into the
        // container is invisible to the tenant-scoped query, so implicit route-model
        // binding 404s before the Policy (and thus authorize('reschedule', ...)) ever runs.
        $outsider = User::factory()->employee()->create();
        $booking = Booking::factory()->confirmed()->create();

        $this->actingAs($outsider)
            ->get("/dashboard/bookings/{$booking->id}/reschedule-slots?date=2026-08-17")
            ->assertNotFound();
    }

    public function test_reschedule_slots_endpoint_does_not_500_on_a_non_string_date_query_param(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->confirmed()->create(['business_id' => $business->id]);

        $this->actingAs($staff)
            ->get("/dashboard/bookings/{$booking->id}/reschedule-slots?date[]=x")
            ->assertOk()
            ->assertExactJson(['slots' => []]);
    }

    public function test_the_bookings_index_exposes_the_business_id_for_the_realtime_channel(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->actingAs($staff)
            ->get('/dashboard/bookings')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Index')
                ->where('businessId', $business->id)
            );
    }

    public function test_it_filters_by_status(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);

        Booking::factory()->create(['business_id' => $business->id, 'status' => BookingStatus::Pending]);
        $confirmed = Booking::factory()->confirmed()->create(['business_id' => $business->id]);
        Booking::factory()->cancelled()->create(['business_id' => $business->id]);

        $this->actingAs($staff)
            ->get('/dashboard/bookings?status=confirmed')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Index')
                ->has('bookings', 1)
                ->where('bookings.0.id', $confirmed->id)
            );
    }

    public function test_it_filters_by_employee(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $employeeA = User::factory()->employee()->create(['business_id' => $business->id]);
        $employeeB = User::factory()->employee()->create(['business_id' => $business->id]);

        $bookingA = Booking::factory()->create(['business_id' => $business->id, 'employee_id' => $employeeA->id]);
        Booking::factory()->create(['business_id' => $business->id, 'employee_id' => $employeeB->id]);

        $this->actingAs($staff)
            ->get("/dashboard/bookings?employee_id={$employeeA->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Index')
                ->has('bookings', 1)
                ->where('bookings.0.id', $bookingA->id)
            );
    }

    public function test_it_filters_from_a_date(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $today = $this->nextMonday();

        Booking::factory()->create([
            'business_id' => $business->id,
            'starts_at' => $today->subWeek()->setTime(10, 0),
            'ends_at' => $today->subWeek()->setTime(10, 30),
        ]);
        $future = Booking::factory()->create([
            'business_id' => $business->id,
            'starts_at' => $today->setTime(10, 0),
            'ends_at' => $today->setTime(10, 30),
        ]);

        $this->actingAs($staff)
            ->get("/dashboard/bookings?from={$today->format('Y-m-d')}")
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Index')
                ->has('bookings', 1)
                ->where('bookings.0.id', $future->id)
            );
    }

    public function test_an_employee_id_from_another_business_returns_nothing(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        Booking::factory()->count(2)->create(['business_id' => $business->id]);

        $otherBusiness = Business::factory()->create();
        $outsiderEmployee = User::factory()->employee()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($staff)
            ->get("/dashboard/bookings?employee_id={$outsiderEmployee->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Index')
                ->has('bookings', 0)
            );
    }

    public function test_without_filters_it_returns_every_booking_of_the_business(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        Booking::factory()->count(3)->create(['business_id' => $business->id]);
        Booking::factory()->create();

        $this->actingAs($staff)
            ->get('/dashboard/bookings')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Bookings/Index')
                ->has('bookings', 3)
            );
    }
}
