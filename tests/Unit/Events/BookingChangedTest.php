<?php

namespace Tests\Unit\Events;

use App\Enums\BookingChange;
use App\Events\Broadcasting\BookingChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use PHPUnit\Framework\TestCase;

class BookingChangedTest extends TestCase
{
    private function event(): BookingChanged
    {
        return new BookingChanged(
            businessId: 7,
            bookingId: 42,
            change: BookingChange::Confirmed,
            updatedAt: '2026-08-20T18:04:11+00:00',
        );
    }

    public function test_it_declares_queued_broadcasting_after_commit(): void
    {
        $event = $this->event();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_it_broadcasts_on_the_private_business_channel(): void
    {
        $channels = $this->event()->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-business.7', $channels[0]->name);
    }

    public function test_it_uses_a_stable_event_name(): void
    {
        $this->assertSame('booking.changed', $this->event()->broadcastAs());
    }

    public function test_the_payload_is_exactly_the_three_agreed_keys(): void
    {
        $this->assertSame([
            'booking_id' => 42,
            'change' => 'confirmed',
            'updated_at' => '2026-08-20T18:04:11+00:00',
        ], $this->event()->broadcastWith());
    }

    public function test_the_payload_never_carries_the_routing_business_id(): void
    {
        // businessId identifica el canal, no es dato del cliente. Si alguien
        // borra broadcastWith(), Laravel serializa las propiedades públicas
        // del evento y businessId se filtraría al navegador.
        $this->assertArrayNotHasKey('businessId', $this->event()->broadcastWith());
        $this->assertArrayNotHasKey('business_id', $this->event()->broadcastWith());
    }
}
