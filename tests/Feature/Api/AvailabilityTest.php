<?php

namespace Tests\Feature\Api;

use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Models\Business;
use App\Models\BusinessHoliday;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_available_slots_for_staff(): void
    {
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 60, 'buffer_minutes' => 15]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        // Create schedule for Wednesday (day 3)
        Schedule::factory()
            ->for($business)
            ->for($employee, 'employee')
            ->create([
                'day_of_week' => DayOfWeek::Wednesday,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'is_active' => true,
            ]);

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        // Query for a Wednesday
        $date = Carbon::now()->next(Carbon::WEDNESDAY)->format('Y-m-d');

        $response = $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data', 'message', 'errors']);

        // Verify slots are returned
        $this->assertGreaterThan(0, count($response['data']));

        // Verify each slot has starts_at and ends_at
        foreach ($response['data'] as $slot) {
            $this->assertArrayHasKey('starts_at', $slot);
            $this->assertArrayHasKey('ends_at', $slot);
        }
    }

    public function test_returns_available_slots_for_customer(): void
    {
        $business = Business::factory()->create(['slug' => 'barberia-juan', 'timezone' => 'America/Argentina/Buenos_Aires']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        Schedule::factory()
            ->for($business)
            ->for($employee, 'employee')
            ->create([
                'day_of_week' => DayOfWeek::Monday,
                'start_time' => '10:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]);

        $customer = User::factory()->customer()->create();
        Sanctum::actingAs($customer, [], 'sanctum');

        $date = Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d');

        $response = $this->getJson("/api/businesses/barberia-juan/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertGreaterThan(0, count($response['data']));
    }

    public function test_validates_required_parameters(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        // Missing all parameters
        $this->getJson('/api/availability')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_validates_date_format(): void
    {
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date=invalid-date")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_returns_404_for_unknown_service(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $date = Carbon::now()->format('Y-m-d');

        $this->getJson("/api/availability?service_id=9999&employee_id={$employee->id}&date={$date}")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_returns_404_for_unknown_employee(): void
    {
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create();
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $date = Carbon::now()->format('Y-m-d');

        $this->getJson("/api/availability?service_id={$service->id}&employee_id=9999&date={$date}")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    public function test_only_sees_services_and_employees_of_its_own_business(): void
    {
        $business1 = Business::factory()->create();
        $business2 = Business::factory()->create();

        $service1 = Service::factory()->for($business1)->create();
        $service2 = Service::factory()->for($business2)->create();

        $employee1 = User::factory()->employee()->create(['business_id' => $business1->id]);
        $employee2 = User::factory()->employee()->create(['business_id' => $business2->id]);

        $owner1 = User::factory()->owner()->create(['business_id' => $business1->id]);
        Sanctum::actingAs($owner1, [], 'sanctum');

        $date = Carbon::now()->format('Y-m-d');

        // Try to access service from other business
        $this->getJson("/api/availability?service_id={$service2->id}&employee_id={$employee1->id}&date={$date}")
            ->assertStatus(404);

        // Try to access employee from other business
        $this->getJson("/api/availability?service_id={$service1->id}&employee_id={$employee2->id}&date={$date}")
            ->assertStatus(404);
    }

    public function test_returns_empty_slots_when_no_schedule(): void
    {
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        // No schedule for this employee
        $date = Carbon::now()->format('Y-m-d');

        $response = $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_respects_schedule_breaks(): void
    {
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        $schedule = Schedule::factory()
            ->for($business)
            ->for($employee, 'employee')
            ->create([
                'day_of_week' => DayOfWeek::Friday,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'is_active' => true,
            ]);

        // Create a break from 12:00 to 13:00
        $schedule->breaks()->create([
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        // Query for the specific Friday date
        $targetDate = CarbonImmutable::parse('next friday', $business->timezone);
        $date = $targetDate->format('Y-m-d');

        $response = $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Build break window using the same target date
        $breakStart = $targetDate->setTime(12, 0, 0);
        $breakEnd = $targetDate->setTime(13, 0, 0);

        // Verify no slots fall during the break
        foreach ($response['data'] as $slot) {
            $startTime = CarbonImmutable::parse($slot['starts_at']);
            $endTime = CarbonImmutable::parse($slot['ends_at']);

            // If slot starts at or after break end, it's OK
            // If slot ends at or before break start, it's OK
            // Otherwise, it overlaps and shouldn't be in the results
            $this->assertTrue(
                $endTime->lte($breakStart) || $startTime->gte($breakEnd),
                "Slot {$slot['starts_at']} - {$slot['ends_at']} overlaps with break {$breakStart->toIso8601String()}-{$breakEnd->toIso8601String()}"
            );
        }
    }

    public function test_requires_authentication(): void
    {
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create();
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $date = Carbon::now()->format('Y-m-d');

        $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}")
            ->assertStatus(401);

        $this->getJson("/api/businesses/any-slug/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}")
            ->assertStatus(401);
    }

    public function test_slots_are_formatted_in_business_timezone_with_offset(): void
    {
        // Use a non-UTC timezone to ensure offset is visible
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        Schedule::factory()
            ->for($business)
            ->for($employee, 'employee')
            ->create([
                'day_of_week' => DayOfWeek::Monday,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'is_active' => true,
            ]);

        $owner = User::factory()->owner()->create(['business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $targetDate = CarbonImmutable::parse('next monday', $business->timezone);
        $date = $targetDate->format('Y-m-d');

        $response = $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}");

        $response->assertOk();

        // Verify timestamps are in ISO-8601 format with timezone offset, not UTC
        $this->assertNotEmpty($response['data']);
        $slot = $response['data'][0];

        // The timestamp should contain a timezone offset like -03:00, not a literal Z
        $this->assertStringContainsString('-03:00', $slot['starts_at'], 'Timestamp should be in business timezone (Buenos Aires = -03:00)');
        $this->assertStringNotContainsString('Z', $slot['starts_at'], 'Timestamp should not use UTC Z notation');

        // Verify it's a proper ISO-8601 string
        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/',
            $slot['starts_at'],
            'Timestamp should match ISO-8601 format with timezone offset'
        );
    }

    public function test_a_business_holiday_empties_the_availability(): void
    {
        $business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);

        Schedule::factory()
            ->for($business)
            ->for($employee, 'employee')
            ->create([
                'day_of_week' => DayOfWeek::Wednesday,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'is_active' => true,
            ]);

        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        Sanctum::actingAs($owner, [], 'sanctum');

        $date = Carbon::now()->next(Carbon::WEDNESDAY)->format('Y-m-d');

        BusinessHoliday::factory()->create([
            'business_id' => $business->id,
            'starts_on' => $date,
            'ends_on' => $date,
        ]);

        $this->getJson("/api/availability?service_id={$service->id}&employee_id={$employee->id}&date={$date}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }
}
