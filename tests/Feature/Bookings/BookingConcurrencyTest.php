<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CreateBooking;
use App\Enums\DayOfWeek;
use App\Models\Business;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse('next monday', $timezone)->startOfDay();
    }

    public function test_second_request_for_the_same_slot_is_rejected_once_the_first_has_claimed_it(): void
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $employee = User::factory()->employee()->create(['business_id' => $business->id]);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
        $customerA = User::factory()->customer()->create();
        $customerB = User::factory()->customer()->create();
        $slot = $this->nextMonday()->setTime(9, 0);

        Schedule::factory()->create([
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_active' => true,
        ]);

        $service->employees()->attach($employee->id);

        $payload = fn (User $customer) => [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $slot->toIso8601String(),
            'source' => 'web',
            'notes' => null,
        ];

        $first = app(CreateBooking::class)->handle($business, $payload($customerA), $customerA);
        $this->assertNotNull($first->id);

        $this->expectException(ValidationException::class);
        app(CreateBooking::class)->handle($business, $payload($customerB), $customerB);
    }
}
