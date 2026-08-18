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

class PaymentsApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('cli')->plainTextToken;
    }

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

    public function test_a_customer_initiates_and_reads_a_payment(): void
    {
        [$booking, $customer] = $this->scenario();
        $token = $this->tokenFor($customer);

        $created = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/bookings/{$booking->id}/payments")
            ->assertCreated()
            ->assertJsonStructure([
                'success', 'message', 'errors',
                'data' => ['id', 'status', 'amount', 'currency', 'expires_at', 'checkout_url'],
            ])
            ->json('data');

        $this->assertSame('pending', $created['status']);
        $this->assertStringContainsString('/demo/pagos/', $created['checkout_url']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/bookings/{$booking->id}/payments")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/bookings/{$booking->id}/payments/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $created['id']);
    }

    public function test_staff_of_the_business_may_initiate(): void
    {
        [$booking, , $owner] = $this->scenario();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/bookings/{$booking->id}/payments")
            ->assertCreated();
    }

    public function test_repeated_initiation_returns_the_same_attempt_with_200(): void
    {
        [$booking, $customer] = $this->scenario();
        $token = $this->tokenFor($customer);

        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/bookings/{$booking->id}/payments")->assertCreated()->json('data.id');

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/bookings/{$booking->id}/payments")->assertOk()->json('data.id');

        $this->assertSame($first, $second);
    }

    public function test_another_customer_cannot_touch_the_payment(): void
    {
        [$booking] = $this->scenario();
        $intruder = User::factory()->customer()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($intruder))
            ->postJson("/api/bookings/{$booking->id}/payments")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_staff_from_another_business_cannot_read_the_payments(): void
    {
        [$booking] = $this->scenario();
        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $booking->business_id,
            'status' => PaymentStatus::Pending,
        ]);

        $otherBusiness = Business::factory()->create();
        $otherOwner = User::factory()->create(['role' => Role::Owner, 'business_id' => $otherBusiness->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($otherOwner))
            ->getJson("/api/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertNotFound();
    }

    public function test_a_payment_from_another_booking_is_not_reachable(): void
    {
        [$booking, $customer] = $this->scenario();
        [$otherBooking] = $this->scenario();
        $foreign = Payment::factory()->for($otherBooking)->create([
            'business_id' => $otherBooking->business_id,
            'status' => PaymentStatus::Pending,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->getJson("/api/bookings/{$booking->id}/payments/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_checkout_url_disappears_once_the_attempt_is_dead(): void
    {
        [$booking, $customer] = $this->scenario();
        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $booking->business_id,
            'status' => PaymentStatus::Rejected,
        ]);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->getJson("/api/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertOk()
            ->json('data');

        $this->assertNull($data['checkout_url']);
        $this->assertArrayNotHasKey('last_snapshot', $data);
    }

    public function test_an_expired_window_hides_the_checkout_url(): void
    {
        [$booking, $customer] = $this->scenario(['payment_expires_at' => now()->subMinute()]);
        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $booking->business_id,
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->getJson("/api/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertOk()
            ->json('data');

        $this->assertNull($data['checkout_url']);
    }

    public function test_initiating_on_a_booking_without_deposit_returns_422(): void
    {
        [$booking, $customer] = $this->scenario([
            'deposit_amount' => null,
            'status' => BookingStatus::Confirmed,
            'payment_expires_at' => null,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->postJson("/api/bookings/{$booking->id}/payments")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
