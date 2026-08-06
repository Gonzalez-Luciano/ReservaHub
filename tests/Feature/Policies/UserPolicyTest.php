<?php

namespace Tests\Feature\Policies;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_user_in_same_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->assertTrue($owner->can('update', $employee));
        $this->assertTrue($owner->can('delete', $employee));
    }

    public function test_owner_cannot_manage_user_in_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $ownerA = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessA->id]);
        $employeeB = User::factory()->create(['role' => Role::Employee, 'business_id' => $businessB->id]);

        $this->assertFalse($ownerA->can('update', $employeeB));
        $this->assertFalse($ownerA->can('delete', $employeeB));
    }

    public function test_employee_cannot_manage_other_users(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $coworker = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->assertFalse($employee->can('update', $coworker));
    }
}
