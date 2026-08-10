<?php

namespace Tests\Unit\Policies;

use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use App\Policies\BookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private BookingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new BookingPolicy;
    }

    public function test_customer_can_view_their_own_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        $this->assertTrue($this->policy->view($customer, $booking));
    }

    public function test_customer_cannot_view_someone_elses_booking(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create();

        $this->assertFalse($this->policy->view($customer, $booking));
    }

    public function test_staff_can_view_a_booking_of_their_own_business(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $booking = Booking::factory()->create(['business_id' => $business->id]);

        $this->assertTrue($this->policy->view($staff, $booking));
    }

    public function test_staff_cannot_view_a_booking_of_another_business(): void
    {
        $staff = User::factory()->employee()->create();
        $booking = Booking::factory()->create();

        $this->assertFalse($this->policy->view($staff, $booking));
    }

    public function test_view_any_allows_staff_of_the_business_and_rejects_others(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $outsider = User::factory()->employee()->create();
        $customer = User::factory()->customer()->create();

        $this->assertTrue($this->policy->viewAny($staff, $business));
        $this->assertFalse($this->policy->viewAny($outsider, $business));
        $this->assertFalse($this->policy->viewAny($customer, $business));
    }

    public function test_any_business_staff_role_can_create_by_staff(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        $this->assertTrue($this->policy->createByStaff($employee, $business));
    }

    public function test_staff_of_another_business_cannot_create_by_staff(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create();

        $this->assertFalse($this->policy->createByStaff($employee, $business));
    }

    public function test_only_customer_role_can_create_by_customer(): void
    {
        $customer = User::factory()->customer()->create();
        $employee = User::factory()->employee()->create();

        $this->assertTrue($this->policy->createByCustomer($customer));
        $this->assertFalse($this->policy->createByCustomer($employee));
    }

    public function test_customer_can_cancel_within_window_staff_can_cancel_anytime(): void
    {
        $business = Business::factory()->create(['cancellation_hours' => 24, 'timezone' => 'UTC']);
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $bookingSoon = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'starts_at' => CarbonImmutable::now('UTC')->addHour(),
        ]);

        $this->assertFalse($this->policy->cancel($customer, $bookingSoon));
        $this->assertTrue($this->policy->cancel($staff, $bookingSoon));
    }

    public function test_only_staff_can_confirm_complete_or_mark_no_show(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->employee()->create(['business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['business_id' => $business->id]);

        $this->assertTrue($this->policy->confirm($staff, $booking));
        $this->assertFalse($this->policy->confirm($customer, $booking));
        $this->assertTrue($this->policy->complete($staff, $booking));
        $this->assertFalse($this->policy->complete($customer, $booking));
        $this->assertTrue($this->policy->markNoShow($staff, $booking));
        $this->assertFalse($this->policy->markNoShow($customer, $booking));
    }
}
