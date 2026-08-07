<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServicesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('services'));
        $this->assertTrue(Schema::hasColumns('services', [
            'id', 'business_id', 'name', 'description', 'duration_minutes',
            'buffer_minutes', 'price', 'deposit_amount', 'is_active',
            'created_at', 'updated_at',
        ]));
    }
}
