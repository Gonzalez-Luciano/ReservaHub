<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeServiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_service_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('employee_service'));
        $this->assertTrue(Schema::hasColumns('employee_service', [
            'employee_id', 'service_id', 'created_at', 'updated_at',
        ]));
    }
}
