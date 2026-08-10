<?php

namespace Tests\Feature\Public;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyBookingsTest extends TestCase
{
    use RefreshDatabase;

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
}
