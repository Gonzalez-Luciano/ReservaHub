<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingStatusHistoriesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_status_histories_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('booking_status_histories'));
        $this->assertTrue(Schema::hasColumns('booking_status_histories', [
            'id', 'booking_id', 'from_status', 'to_status', 'changed_by', 'notes', 'created_at',
        ]));
    }
}
