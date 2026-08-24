<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $employee;

    private User $otherEmployee;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Miércoles fijo (mismo ancla que la Task 1): dentro del día hay
        // margen de sobra para cualquier ventana relativa a "ahora". El
        // negocio usa una zona horaria distinta de UTC a propósito, para que
        // test_today_is_resolved_in_the_business_timezone ejercite de verdad
        // la conversión y no coincida con UTC por casualidad.
        $this->travelTo(CarbonImmutable::parse('2026-01-07 08:00', 'UTC'));

        $this->business = Business::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $this->owner = User::factory()->owner()->create(['business_id' => $this->business->id]);
        $this->employee = User::factory()->employee()->create(['business_id' => $this->business->id]);
        $this->otherEmployee = User::factory()->employee()->create(['business_id' => $this->business->id]);
        $this->service = Service::factory()->for($this->business)->create();
    }

    private function pendingBookingExpiringAt(CarbonImmutable $expiresAt): Booking
    {
        $startsAt = CarbonImmutable::now()->addDay()->setTime(10, 0);

        return Booking::factory()->create([
            'business_id' => $this->business->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'status' => BookingStatus::Pending,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'deposit_amount' => 20,
            'payment_expires_at' => $expiresAt,
        ]);
    }

    private function confirmedBookingAt(CarbonImmutable $startsAt, ?User $employee = null): Booking
    {
        return Booking::factory()->confirmed()->create([
            'business_id' => $this->business->id,
            'employee_id' => ($employee ?? $this->employee)->id,
            'service_id' => $this->service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);
    }

    public function test_expiring_soon_excludes_an_already_expired_booking(): void
    {
        // Vencida hace un minuto pero todavía pending: el scheduler no corrió.
        $this->pendingBookingExpiringAt(CarbonImmutable::now()->subMinute());

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.expiring_soon', 0));
    }

    public function test_expiring_soon_includes_a_booking_inside_the_window(): void
    {
        $this->pendingBookingExpiringAt(CarbonImmutable::now()->addMinutes(10));

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.expiring_soon', 1));
    }

    public function test_the_attention_queue_never_lists_a_booking_twice(): void
    {
        $booking = $this->pendingBookingExpiringAt(CarbonImmutable::now()->addMinutes(5));

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(function ($page) use ($booking) {
                $ids = collect($page->toArray()['props']['attention'])->pluck('id');

                $this->assertSame(1, $ids->filter(fn ($id) => $id === $booking->id)->count());
                $this->assertSame('expiring_soon', $page->toArray()['props']['attention'][0]['kind']);
            });
    }

    public function test_today_is_resolved_in_the_business_timezone(): void
    {
        // 23:30 en Buenos Aires es 02:30 UTC del día siguiente. Si la ventana
        // se calculara en UTC, esta reserva caería fuera de "hoy".
        $this->travelTo(CarbonImmutable::parse('2026-01-05 23:30', 'America/Argentina/Buenos_Aires'));
        $this->confirmedBookingAt(CarbonImmutable::parse('2026-01-05 23:45', 'America/Argentina/Buenos_Aires'));

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.today_total', 1));
    }

    public function test_an_employee_only_sees_their_own_agenda(): void
    {
        $this->confirmedBookingAt(CarbonImmutable::now()->setTime(10, 0), $this->otherEmployee);

        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.today_total', 0));
    }

    public function test_a_business_never_sees_another_businesses_metrics(): void
    {
        $other = Business::factory()->create();
        Booking::factory()->create(['business_id' => $other->id, 'starts_at' => CarbonImmutable::now()->setTime(10, 0)]);

        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('metrics.today_total', 0));
    }
}
