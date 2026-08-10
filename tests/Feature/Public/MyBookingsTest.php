<?php

namespace Tests\Feature\Public;

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

class MyBookingsTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_customer_sees_only_their_own_bookings_across_businesses(): void
    {
        $customer = User::factory()->customer()->create();
        Booking::factory()->count(2)->create(['customer_id' => $customer->id]);
        Booking::factory()->create();

        $this->actingAs($customer)->get('/mis-reservas')
            ->assertInertia(fn ($page) => $page->component('Public/MyBookings/Index')->has('bookings', 2));
    }

    public function test_customer_cancels_their_own_booking(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(3)->addMinutes(30),
        ]);

        $this->actingAs($customer)->post("/mis-reservas/{$booking->id}/cancel")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_customer_cannot_cancel_someone_elses_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $this->actingAs($customer)->post("/mis-reservas/{$booking->id}/cancel")->assertForbidden();
    }

    public function test_customer_reschedules_their_own_booking(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
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

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $this->actingAs($customer)
            ->put("/mis-reservas/{$booking->id}/reschedule", [
                'starts_at' => $date->setTime(9, 30)->toIso8601String(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'starts_at' => $date->setTime(9, 30)->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_customer_cannot_reschedule_someone_elses_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $this->actingAs($customer)
            ->put("/mis-reservas/{$booking->id}/reschedule", [
                'starts_at' => CarbonImmutable::now('UTC')->addDays(5)->toIso8601String(),
            ])
            ->assertForbidden();
    }

    public function test_reschedule_slots_endpoint_returns_available_slots_excluding_the_booking_itself(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customer = User::factory()->customer()->create();
        $date = CarbonImmutable::parse('next monday', 'UTC')->startOfDay();

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
        ]);

        $response = $this->actingAs($customer)
            ->get("/mis-reservas/{$booking->id}/reschedule-slots?date={$date->format('Y-m-d')}")
            ->assertOk()
            ->json();

        $this->assertCount(2, $response['slots']);
    }

    public function test_reschedule_slots_endpoint_requires_reschedule_authorization(): void
    {
        $otherCustomer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $this->actingAs($otherCustomer)
            ->get("/mis-reservas/{$booking->id}/reschedule-slots?date=2026-08-17")
            ->assertForbidden();
    }

    public function test_reschedule_slots_endpoint_returns_empty_slots_for_malformed_date_array(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(5),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(5)->addMinutes(30),
        ]);

        $response = $this->actingAs($customer)
            ->get("/mis-reservas/{$booking->id}/reschedule-slots?date[]=x")
            ->assertOk()
            ->json();

        $this->assertSame([], $response['slots']);
    }
}
