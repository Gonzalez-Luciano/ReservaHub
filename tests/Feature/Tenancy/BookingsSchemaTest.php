<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('bookings'));
        $this->assertTrue(Schema::hasColumns('bookings', [
            'id', 'business_id', 'customer_id', 'employee_id', 'service_id',
            'starts_at', 'ends_at', 'status', 'price', 'deposit_amount',
            'notes', 'source', 'cancelled_at', 'created_at', 'updated_at',
        ]));
    }
}
