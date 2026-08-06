<?php

namespace Tests\Feature\Policies;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_own_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->assertTrue($owner->can('view', $business));
        $this->assertTrue($owner->can('update', $business));
    }

    public function test_owner_cannot_manage_another_business(): void
    {
        $ownBusiness = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $ownBusiness->id]);

        $this->assertFalse($owner->can('view', $otherBusiness));
        $this->assertFalse($owner->can('update', $otherBusiness));
    }

    public function test_employee_cannot_manage_business_settings(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->assertFalse($employee->can('update', $business));
    }
}
