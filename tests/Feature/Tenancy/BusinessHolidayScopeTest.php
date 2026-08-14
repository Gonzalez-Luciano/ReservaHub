<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\BusinessHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHolidayScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_global_scope_hides_holidays_from_other_businesses(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();

        $own = BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'name' => 'Feriado propio',
        ]);
        BusinessHoliday::factory()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Feriado ajeno',
        ]);

        app()->instance(Business::class, $business);

        $holidays = BusinessHoliday::all();

        $this->assertCount(1, $holidays);
        $this->assertSame($own->id, $holidays->first()->id);
    }

    public function test_it_stamps_the_current_business_on_create(): void
    {
        $business = Business::factory()->create();
        app()->instance(Business::class, $business);

        $holiday = BusinessHoliday::create([
            'name' => 'Día de la Independencia',
            'starts_on' => '2026-07-09',
            'ends_on' => '2026-07-09',
        ]);

        $this->assertSame($business->id, $holiday->business_id);
    }

    public function test_it_casts_the_date_columns(): void
    {
        $business = Business::factory()->create();

        $holiday = BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => '2026-12-24',
            'ends_on' => '2027-01-02',
        ]);

        $this->assertSame('2026-12-24', $holiday->fresh()->starts_on->toDateString());
        $this->assertSame('2027-01-02', $holiday->fresh()->ends_on->toDateString());
    }
}
