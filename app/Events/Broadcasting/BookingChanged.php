<?php

namespace App\Events\Broadcasting;

use App\Enums\BookingChange;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Único evento de broadcast de la aplicación. Vive en Events\Broadcasting y no
 * en Events\ a secas para que la frontera quede visible: lo de arriba es
 * dominio, lo de acá es transporte.
 *
 * Sin SerializesModels y sin ninguna propiedad Model: el job encolado nunca
 * vuelve a buscar la reserva en la base, así que no puede toparse con
 * BusinessScope ni filtrar un campo nuevo del modelo por accidente.
 */
class BookingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $bookingId,
        public readonly BookingChange $change,
        public readonly string $updatedAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.'.$this->businessId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.changed';
    }

    /**
     * Pista de invalidación, no datos. El cliente recarga el estado canónico
     * por HTTP, donde las Policies siguen siendo la autoridad.
     *
     * broadcastWith() es obligatorio, no decorativo: sin él Laravel serializa
     * las propiedades públicas y `businessId` — que es enrutamiento — viajaría
     * al navegador.
     *
     * @return array{booking_id: int, change: string, updated_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'change' => $this->change->value,
            'updated_at' => $this->updatedAt,
        ];
    }
}
