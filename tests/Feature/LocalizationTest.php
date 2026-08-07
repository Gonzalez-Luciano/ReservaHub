<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_default_validation_messages_are_in_spanish(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $response = $this->actingAs($owner)->post('/dashboard/services', []);

        $response->assertSessionHasErrors(['name']);
        $errors = session('errors')->getBag('default');

        $this->assertStringContainsString('obligatorio', $errors->first('name'));
    }
}
