<?php

namespace Tests\Feature\Middleware;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleInertiaRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shares_authenticated_users_role(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $owner->id)
            ->where('auth.user.role', 'owner'));
    }

    public function test_shares_null_user_for_guests(): void
    {
        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page->where('auth.user', null));
    }

    public function test_it_shares_the_business_for_staff(): void
    {
        $business = Business::factory()->create(['name' => 'Peluquería Demo']);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);

        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.business.name', 'Peluquería Demo')
                ->has('auth.user.email'));
    }
}
