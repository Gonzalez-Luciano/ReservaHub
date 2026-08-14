<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: User}
     */
    private function businessWithOwner(array $attributes = []): array
    {
        $business = Business::factory()->create($attributes);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        return [$business, $owner];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Peluquería Nueva',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'cancellation_hours' => 12,
        ], $overrides);
    }

    public function test_an_owner_sees_the_settings_page(): void
    {
        [$business, $owner] = $this->businessWithOwner(['name' => 'Peluquería Vieja']);

        $this->actingAs($owner)->get('/dashboard/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Settings/Edit')
                ->where('business.name', 'Peluquería Vieja')
                ->has('currencies'));
    }

    public function test_an_owner_updates_the_settings(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload())
            ->assertRedirect('/dashboard/settings');

        $business->refresh();

        $this->assertSame('Peluquería Nueva', $business->name);
        $this->assertSame('America/Argentina/Buenos_Aires', $business->timezone);
        $this->assertSame('ARS', $business->currency);
        $this->assertSame(12, $business->cancellation_hours);
    }

    public function test_an_employee_cannot_update_the_settings(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->actingAs($employee)->put('/dashboard/settings', $this->payload())->assertForbidden();
    }

    public function test_the_slug_and_active_flag_are_ignored(): void
    {
        [$business, $owner] = $this->businessWithOwner();
        $originalSlug = $business->slug;

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload([
            'slug' => 'slug-secuestrado',
            'is_active' => false,
        ]))->assertRedirect('/dashboard/settings');

        $business->refresh();

        $this->assertSame($originalSlug, $business->slug);
        $this->assertTrue($business->is_active);
    }

    public function test_it_rejects_an_unsupported_currency_and_an_invalid_timezone(): void
    {
        [$business, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload(['currency' => 'ABC']))
            ->assertSessionHasErrors('currency');

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload(['timezone' => 'Marte/Olympus']))
            ->assertSessionHasErrors('timezone');
    }

    public function test_changing_the_timezone_does_not_move_a_persisted_booking(): void
    {
        [$business, $owner] = $this->businessWithOwner(['timezone' => 'UTC']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30]);

        $startsAt = CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC');

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);

        $this->actingAs($owner)->put('/dashboard/settings', $this->payload([
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]))->assertRedirect('/dashboard/settings');

        $this->assertSame(
            $startsAt->toIso8601String(),
            $booking->fresh()->starts_at->utc()->toIso8601String(),
        );
    }
}
