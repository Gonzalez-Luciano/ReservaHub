<?php

namespace Tests\Unit\Models;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_enum(): void
    {
        $user = User::factory()->create(['role' => Role::Owner]);

        $this->assertSame(Role::Owner, $user->fresh()->role);
    }

    public function test_role_helpers(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => Business::factory()]);
        $customer = User::factory()->create(['role' => Role::Customer, 'business_id' => null]);

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->isAdmin());
        $this->assertTrue($owner->hasBusiness());
        $this->assertFalse($customer->hasBusiness());
    }

    public function test_business_relation(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->assertTrue($user->business->is($business));
    }
}
