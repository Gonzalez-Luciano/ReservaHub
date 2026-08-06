<?php

namespace Tests\Feature\Middleware;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureBusinessContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_user_without_business_gets_403(): void
    {
        $customer = User::factory()->create(['role' => Role::Customer, 'business_id' => null]);

        $response = $this->actingAs($customer)->get('/dashboard');

        $response->assertForbidden();
    }

    public function test_owner_can_access_dashboard_with_business_bound(): void
    {
        $business = Business::factory()->create(['name' => 'Peluquería Norte']);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('business.name', 'Peluquería Norte'));
    }

    public function test_inactive_user_gets_403(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create([
            'role' => Role::Owner,
            'business_id' => $business->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertForbidden();
    }

    public function test_user_with_inactive_business_gets_403(): void
    {
        $business = Business::factory()->create(['is_active' => false]);
        $owner = User::factory()->create([
            'role' => Role::Owner,
            'business_id' => $business->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertForbidden();
    }
}
