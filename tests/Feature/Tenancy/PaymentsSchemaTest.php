<?php

namespace Tests\Feature\Tenancy;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentsSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWithDeposit(?Business $business = null): Booking
    {
        $business ??= Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => 10]);

        return Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'deposit_amount' => 10,
        ]);
    }

    public function test_payments_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('payments', [
            'id', 'business_id', 'booking_id', 'provider', 'external_id', 'status',
            'amount', 'currency', 'expires_at', 'paid_at', 'applied_at',
            'application_outcome', 'failure_reason', 'last_snapshot',
            'last_reconcile_attempt_at', 'last_reconciled_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_webhook_events_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('webhook_events', [
            'id', 'provider', 'external_event_id', 'payment_external_id', 'payload',
            'status', 'outcome_reason', 'attempts', 'last_error', 'received_at',
            'processed_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('simulated_provider_payments', [
            'id', 'external_id', 'status', 'amount', 'currency', 'approved_at',
            'expires_at', 'payload', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumn('bookings', 'payment_expires_at'));
    }

    public function test_only_one_pending_payment_per_booking_is_allowed(): void
    {
        $booking = $this->bookingWithDeposit();

        Payment::factory()->for($booking)->create(['status' => PaymentStatus::Pending]);

        $this->expectException(QueryException::class);

        Payment::factory()->for($booking)->create(['status' => PaymentStatus::Pending]);
    }

    public function test_terminal_payments_may_repeat_for_the_same_booking(): void
    {
        $booking = $this->bookingWithDeposit();

        Payment::factory()->for($booking)->create(['status' => PaymentStatus::Rejected]);
        Payment::factory()->for($booking)->create(['status' => PaymentStatus::Expired]);
        Payment::factory()->for($booking)->create(['status' => PaymentStatus::Pending]);

        $this->assertSame(3, Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->count());
    }

    public function test_provider_and_external_id_are_unique(): void
    {
        $booking = $this->bookingWithDeposit();
        Payment::factory()->for($booking)->create([
            'status' => PaymentStatus::Rejected,
            'provider' => 'simulated',
            'external_id' => 'sim_pay_dup',
        ]);

        $this->expectException(QueryException::class);

        Payment::factory()->for($this->bookingWithDeposit())->create([
            'status' => PaymentStatus::Rejected,
            'provider' => 'simulated',
            'external_id' => 'sim_pay_dup',
        ]);
    }

    public function test_webhook_event_identity_is_unique_per_provider(): void
    {
        WebhookEvent::factory()->create(['provider' => 'simulated', 'external_event_id' => 'evt_1']);

        $this->expectException(QueryException::class);

        WebhookEvent::factory()->create(['provider' => 'simulated', 'external_event_id' => 'evt_1']);
    }

    public function test_payments_are_scoped_to_the_current_business(): void
    {
        $mine = Business::factory()->create(['currency' => 'ARS']);
        $theirs = Business::factory()->create(['currency' => 'ARS']);

        Payment::factory()->for($this->bookingWithDeposit($mine))->create(['status' => PaymentStatus::Rejected]);
        Payment::factory()->for($this->bookingWithDeposit($theirs))->create(['status' => PaymentStatus::Rejected]);

        app()->instance(Business::class, $mine);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_a_booking_exposes_its_payments(): void
    {
        $booking = $this->bookingWithDeposit();
        Payment::factory()->for($booking)->create(['status' => PaymentStatus::Pending]);

        $this->assertCount(1, $booking->refresh()->payments);
    }
}
