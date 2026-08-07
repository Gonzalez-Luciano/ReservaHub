<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeInvitationsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_invitations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('employee_invitations'));
        $this->assertTrue(Schema::hasColumns('employee_invitations', [
            'id', 'business_id', 'email', 'name', 'token', 'invited_by_id',
            'expires_at', 'accepted_at', 'created_at', 'updated_at',
        ]));
    }
}
