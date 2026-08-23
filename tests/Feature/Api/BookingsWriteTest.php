<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingsWriteTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $employee;

    private Service $service;

    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        // Ver la nota de MyBookingsTest: `$this->monday` se deriva de
        // `next monday`, así que el reloj tiene que estar fijo antes.
        $this->travelTo(CarbonImmutable::parse('2026-01-07 08:00', 'UTC'));

        Notification::fake();

        // Sanctum::actingAs() attaches a Mockery-mocked PersonalAccessToken to
        // the acting User instance. CancelBooking's event carries that same
        // User (as $cancelledBy) to a ShouldQueue listener; the queue's
        // payload serialization then chokes on the mock's broken __sleep(),
        // even under the sync driver (it still serializes for the payload).
        // Queue::fake() short-circuits that push before serialization runs.
        Queue::fake();

        $this->business = Business::factory()->create([
            'slug' => 'barberia-juan',
            'timezone' => 'UTC',
            'cancellation_hours' => 24,
        ]);

        $this->employee = User::factory()->employee()->create(['business_id' => $this->business->id]);

        $this->service = Service::factory()->for($this->business)->create([
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'deposit_amount' => 0,
            'is_active' => true,
        ]);

        $this->service->employees()->attach($this->employee->id);

        Schedule::factory()->create([
            'business_id' => $this->business->id,
            'employee_id' => $this->employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        $this->monday = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();
    }

    public function test_staff_creates_a_booking_for_a_customer(): void
    {
        $customer = User::factory()->customer()->create(['email' => 'cliente@example.com']);
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $this->business->id]), [], 'sanctum');

        $this->postJson('/api/bookings', [
            'customer_email' => 'cliente@example.com',
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
            'notes' => 'Primera vez',
        ])->assertStatus(201)->assertJsonPath('success', true)->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('bookings', [
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'source' => 'api',
            'notes' => 'Primera vez',
        ]);
    }

    public function test_customer_creates_a_booking_through_the_slug(): void
    {
        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson('/api/businesses/barberia-juan/bookings', [
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
        ])->assertStatus(201);

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'business_id' => $this->business->id,
            'source' => 'api',
        ]);
    }

    public function test_a_taken_slot_is_rejected_with_422(): void
    {
        $customer = User::factory()->customer()->create();
        Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson('/api/businesses/barberia-juan/bookings', [
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['starts_at']]);
    }

    public function test_staff_user_cannot_book_through_the_customer_route(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $this->business->id]), [], 'sanctum');

        $this->postJson('/api/businesses/barberia-juan/bookings', [
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->toIso8601String(),
        ])->assertStatus(403);
    }

    public function test_customer_reschedules_their_booking_with_patch(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->patchJson("/api/bookings/{$booking->id}", [
            'starts_at' => $this->monday->setTime(10, 0)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.starts_at', $this->monday->setTime(10, 0)->toIso8601String());
    }

    public function test_reschedule_to_a_time_outside_working_hours_fails(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->patchJson("/api/bookings/{$booking->id}", [
            'starts_at' => $this->monday->setTime(20, 0)->toIso8601String(),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['starts_at']]);
    }

    public function test_customer_cancels_their_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_customer_cannot_cancel_past_the_cancellation_window(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => CarbonImmutable::now('UTC')->addHours(2),
            'ends_at' => CarbonImmutable::now('UTC')->addHours(2)->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/cancel")->assertStatus(403);
    }

    public function test_staff_confirms_a_pending_booking(): void
    {
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Pending,
        ]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $this->business->id]), [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_customer_cannot_confirm_a_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->monday->setTime(9, 0)->utc(),
            'ends_at' => $this->monday->setTime(9, 30)->utc(),
            'status' => BookingStatus::Pending,
        ]);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson("/api/bookings/{$booking->id}/confirm")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
