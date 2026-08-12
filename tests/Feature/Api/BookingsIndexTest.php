<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(Business $business, ?User $customer = null, array $attributes = []): Booking
    {
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();

        return Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => ($customer ?? User::factory()->customer()->create())->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
        ], $attributes));
    }

    public function test_staff_sees_only_bookings_of_its_own_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $mine = $this->makeBooking($business);
        $this->makeBooking($other);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mine->id)
            ->assertJsonStructure(['data' => ['items', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]]);
    }

    public function test_customer_sees_only_their_own_bookings_across_businesses(): void
    {
        $first = Business::factory()->create();
        $second = Business::factory()->create();
        $customer = User::factory()->customer()->create();
        $this->makeBooking($first, $customer);
        $this->makeBooking($second, $customer);
        $this->makeBooking($first);

        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/bookings')->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_booking_payload_includes_relations_and_business_local_times(): void
    {
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $booking = $this->makeBooking($business, null, [
            'starts_at' => '2026-09-07 13:00:00',
            'ends_at' => '2026-09-07 13:30:00',
        ]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-09-07T10:00:00-03:00')
            ->assertJsonStructure(['data' => ['id', 'status', 'starts_at', 'ends_at', 'price', 'service' => ['id', 'name'], 'employee' => ['id', 'name'], 'customer' => ['id', 'name'], 'business' => ['id', 'slug', 'timezone']]]);
    }

    public function test_customer_cannot_read_someone_elses_booking(): void
    {
        $business = Business::factory()->create();
        $booking = $this->makeBooking($business);

        Sanctum::actingAs(User::factory()->customer()->create(), [], 'sanctum');

        $this->getJson("/api/bookings/{$booking->id}")->assertStatus(404);
    }

    public function test_staff_of_another_business_cannot_read_the_booking(): void
    {
        $business = Business::factory()->create();
        $booking = $this->makeBooking($business);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => Business::factory()->create()->id]), [], 'sanctum');

        $this->getJson("/api/bookings/{$booking->id}")->assertStatus(404);
    }

    public function test_filters_by_status(): void
    {
        $business = Business::factory()->create();
        $this->makeBooking($business, null, ['status' => BookingStatus::Cancelled]);
        $confirmed = $this->makeBooking($business, null, ['status' => BookingStatus::Confirmed]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $confirmed->id);
    }

    public function test_paginates_with_per_page(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create();
        Booking::factory()->count(3)->create([
            'business_id' => $business->id,
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
        ]);

        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.last_page', 2);
    }

    public function test_rejects_an_out_of_range_per_page(): void
    {
        $business = Business::factory()->create();
        Sanctum::actingAs(User::factory()->owner()->create(['business_id' => $business->id]), [], 'sanctum');

        $this->getJson('/api/bookings?per_page=500')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    public function test_deactivated_staff_user_cannot_list_bookings(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->owner()->create(['business_id' => $business->id, 'is_active' => false]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson('/api/bookings')->assertStatus(403);
    }

    public function test_deactivated_customer_cannot_list_bookings(): void
    {
        $customer = User::factory()->customer()->create(['is_active' => false]);
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->getJson('/api/bookings')->assertStatus(403);
    }
}
