<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchedulesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedules_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('schedules'));
        $this->assertTrue(Schema::hasColumns('schedules', [
            'id', 'business_id', 'employee_id', 'day_of_week', 'start_time', 'end_time', 'is_active',
            'created_at', 'updated_at',
        ]));
    }

    public function test_schedule_breaks_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('schedule_breaks'));
        $this->assertTrue(Schema::hasColumns('schedule_breaks', [
            'id', 'schedule_id', 'start_time', 'end_time', 'created_at', 'updated_at',
        ]));
    }
}
