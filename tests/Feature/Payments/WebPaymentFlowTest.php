<?php

namespace Tests\Feature\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Booking, 1: User, 2: User}
     */
    private function scenario(array $overrides = []): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => '10.00']);

        $booking = Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
            'payment_expires_at' => now()->addMinutes(30),
        ], $overrides));

        return [$booking, $customer, $owner];
    }

    public function test_a_customer_starts_the_payment_and_lands_on_the_checkout(): void
    {
        [$booking, $customer] = $this->scenario();

        $response = $this->actingAs($customer)->post("/mis-reservas/{$booking->id}/pagos");

        $response->assertRedirectContains('/demo/pagos/');
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->count());
    }

    public function test_another_customer_cannot_start_it(): void
    {
        [$booking] = $this->scenario();
        $intruder = User::factory()->customer()->create();

        $this->actingAs($intruder)->post("/mis-reservas/{$booking->id}/pagos")->assertNotFound();
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_staff_starts_it_from_the_dashboard(): void
    {
        [$booking, , $owner] = $this->scenario();

        $this->actingAs($owner)->post("/dashboard/bookings/{$booking->id}/pagos")
            ->assertRedirectContains('/demo/pagos/');
    }

    public function test_staff_from_another_business_cannot_start_it(): void
    {
        [$booking] = $this->scenario();
        $otherBusiness = Business::factory()->create();
        $otherOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $otherBusiness->id]);

        $this->actingAs($otherOwner)->post("/dashboard/bookings/{$booking->id}/pagos")->assertNotFound();
    }

    public function test_my_bookings_exposes_the_payment_state(): void
    {
        [$booking, $customer] = $this->scenario();
        Payment::factory()->for($booking)->create([
            'business_id' => $booking->business_id,
            'status' => PaymentStatus::Pending,
        ]);

        $props = $this->actingAs($customer)->get('/mis-reservas')->assertOk()->viewData('page')['props'];

        $this->assertSame('pending', $props['bookings'][0]['payment']['status']);
        $this->assertStringContainsString('/demo/pagos/', $props['bookings'][0]['payment']['checkout_url']);
    }

    public function test_the_dashboard_booking_page_exposes_the_payment_state(): void
    {
        [$booking, , $owner] = $this->scenario();
        Payment::factory()->for($booking)->rejected()->create(['business_id' => $booking->business_id]);

        $props = $this->actingAs($owner)->get("/dashboard/bookings/{$booking->id}")->assertOk()->viewData('page')['props'];

        $this->assertSame('rejected', $props['payments'][0]['status']);
        $this->assertNull($props['payments'][0]['checkout_url']);

        // El botón "Pagar seña" del panel se muestra cuando la reserva está
        // pendiente, requiere seña y no hay un intento vivo en `payments` —
        // estas tres condiciones son las que expone esta misma respuesta.
        $this->assertSame('pending', $props['booking']['status']);
        $this->assertSame('10.00', $props['booking']['deposit_amount']);
        $this->assertFalse(collect($props['payments'])->contains('status', 'pending'));
    }

    public function test_a_booking_without_deposit_shows_no_payment(): void
    {
        [$booking, $customer] = $this->scenario([
            'deposit_amount' => null,
            'status' => BookingStatus::Confirmed,
            'payment_expires_at' => null,
        ]);

        $props = $this->actingAs($customer)->get('/mis-reservas')->assertOk()->viewData('page')['props'];

        $this->assertNull($props['bookings'][0]['payment']);
    }
}
