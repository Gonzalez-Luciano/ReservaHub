<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_businesses_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('businesses'));
        $this->assertTrue(Schema::hasColumns('businesses', [
            'id', 'name', 'slug', 'timezone', 'currency',
            'cancellation_hours', 'logo_path', 'is_active',
            'created_at', 'updated_at',
        ]));
    }

    public function test_users_table_has_tenancy_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['business_id', 'role', 'is_active']));
    }
}
