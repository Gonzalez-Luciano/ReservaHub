<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TimeOffsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_offs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('time_offs'));
        $this->assertTrue(Schema::hasColumns('time_offs', [
            'id', 'business_id', 'employee_id', 'starts_at', 'ends_at', 'reason', 'created_at', 'updated_at',
        ]));
    }
}
