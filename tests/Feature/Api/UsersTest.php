<?php

namespace Tests\Feature\Api;

use App\Actions\Users\SetUserActiveStatus;
use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WithDatabaseSessions;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;
    use WithDatabaseSessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWithDatabaseSessions();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('cli')->plainTextToken;
    }

    public function test_an_owner_deactivates_an_employee_over_the_api(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/users/{$employee->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.is_active', false)
            ->assertJsonPath('data.future_bookings_count', 0)
            ->assertJsonPath('message', 'Usuario desactivado.');

        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_an_owner_over_the_api(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin, 'business_id' => $business->id]);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->putJson("/api/users/{$owner->id}/status", ['is_active' => false])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'No tenés permiso para realizar esta acción.']);

        $this->assertTrue($owner->fresh()->is_active);
    }

    /**
     * Test that deactivating the last active owner raises ValidationException.
     * This is NOT an HTTP test because the HTTP layer would deny it at 403 (policy)
     * before the action's last-owner validation ever runs. So we test the action directly,
     * bypassing the policy, to verify the business logic itself rejects it correctly.
     * This mirrors the web test pattern from Task 7.
     */
    public function test_the_last_active_owner_is_rejected_with_the_validation_envelope(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        try {
            app(SetUserActiveStatus::class)->handle($owner, false);
            $this->fail('Se esperaba una ValidationException al desactivar al último owner activo.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'No podés desactivar al último propietario activo del negocio.',
                $e->errors()['is_active'][0]
            );
        }

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_a_user_from_another_business_is_forbidden(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $stranger = User::factory()->create([
            'role' => Role::Employee,
            'business_id' => Business::factory()->create()->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/users/{$stranger->id}/status", ['is_active' => false])
            ->assertStatus(403);

        $this->assertTrue($stranger->fresh()->is_active);
    }
}
