<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Users\SetUserActiveStatus;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WithDatabaseSessions;
use Tests\TestCase;

class UserStatusTest extends TestCase
{
    use RefreshDatabase;
    use WithDatabaseSessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWithDatabaseSessions();
    }

    private function userFor(Business $business, Role $role): User
    {
        return User::factory()->create(['role' => $role, 'business_id' => $business->id]);
    }

    public function test_an_owner_deactivates_an_employee_and_revokes_their_access(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $employee = $this->userFor($business, Role::Employee);
        $employee->createToken('cli');

        DB::table('sessions')->insert([
            'id' => 'sesion-del-empleado',
            'user_id' => $employee->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$employee->id}/status", ['is_active' => false])
            ->assertRedirect('/dashboard/employees');

        $this->assertFalse($employee->fresh()->is_active);
        $this->assertSame(0, $employee->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-empleado']);
    }

    public function test_an_owner_reactivates_an_employee(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $employee = $this->userFor($business, Role::Employee);
        $employee->update(['is_active' => false]);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$employee->id}/status", ['is_active' => true])
            ->assertRedirect('/dashboard/employees');

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_an_owner(): void
    {
        $business = Business::factory()->create();
        $admin = $this->userFor($business, Role::Admin);
        $owner = $this->userFor($business, Role::Owner);

        $this->actingAs($admin)
            ->put("/dashboard/users/{$owner->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_an_admin_can_deactivate_another_admin_and_an_employee(): void
    {
        $business = Business::factory()->create();
        $admin = $this->userFor($business, Role::Admin);
        $otherAdmin = $this->userFor($business, Role::Admin);
        $employee = $this->userFor($business, Role::Employee);

        $this->actingAs($admin)->put("/dashboard/users/{$otherAdmin->id}/status", ['is_active' => false]);
        $this->actingAs($admin)->put("/dashboard/users/{$employee->id}/status", ['is_active' => false]);

        $this->assertFalse($otherAdmin->fresh()->is_active);
        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_an_employee_cannot_change_anyones_status(): void
    {
        $business = Business::factory()->create();
        $employee = $this->userFor($business, Role::Employee);
        $otherEmployee = $this->userFor($business, Role::Employee);

        $this->actingAs($employee)
            ->put("/dashboard/users/{$otherEmployee->id}/status", ['is_active' => false])
            ->assertForbidden();
    }

    public function test_nobody_can_change_their_own_status(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$owner->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_a_manager_cannot_touch_a_user_from_another_business(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $otherBusiness = Business::factory()->create();
        $stranger = $this->userFor($otherBusiness, Role::Employee);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$stranger->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($stranger->fresh()->is_active);
    }

    /**
     * Exercised at the Action level, not over HTTP: the only actor who can pass
     * `UserPolicy::setActiveStatus` against an owner target is another owner, and
     * the `business` middleware hard-blocks an inactive user before the controller
     * ever runs. So an owner-actor who is active always counts as "another owner
     * remains" and the guard can't fire, while an inactive owner-actor never
     * reaches the route at all — there is no single-request HTTP scenario that
     * can legitimately trigger this invariant. Concurrent HTTP requests can
     * (that's Task 8); here we verify the invariant itself.
     */
    public function test_the_last_active_owner_cannot_be_deactivated(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);

        try {
            app(SetUserActiveStatus::class)->handle($owner, false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_an_owner_can_be_deactivated_when_another_active_owner_remains(): void
    {
        $business = Business::factory()->create();
        $firstOwner = $this->userFor($business, Role::Owner);
        $secondOwner = $this->userFor($business, Role::Owner);

        $this->actingAs($firstOwner)
            ->put("/dashboard/users/{$secondOwner->id}/status", ['is_active' => false])
            ->assertRedirect('/dashboard/employees');

        $this->assertFalse($secondOwner->fresh()->is_active);
    }

    public function test_deactivating_reports_future_bookings_without_cancelling_them(): void
    {
        $business = Business::factory()->create();
        $owner = $this->userFor($business, Role::Owner);
        $employee = $this->userFor($business, Role::Employee);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);

        $futureStart = CarbonImmutable::now('UTC')->addWeek();
        $pastStart = CarbonImmutable::now('UTC')->subWeek();

        $future = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $futureStart,
            'ends_at' => $futureStart->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $pastStart,
            'ends_at' => $pastStart->addMinutes(30),
            'status' => BookingStatus::Completed,
        ]);

        $this->actingAs($owner)
            ->put("/dashboard/users/{$employee->id}/status", ['is_active' => false])
            ->assertSessionHas('future_bookings_count', 1);

        $this->assertSame(BookingStatus::Confirmed, $future->fresh()->status);
    }
}
