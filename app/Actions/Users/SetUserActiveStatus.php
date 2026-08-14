<?php

namespace App\Actions\Users;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use App\Support\UserAccessRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetUserActiveStatus
{
    public function __construct(private readonly UserAccessRevoker $revoker) {}

    /**
     * @return array{user: User, future_bookings_count: int}
     *
     * @throws ValidationException cuando se intenta desactivar al último owner
     *                             activo del negocio.
     */
    public function handle(User $target, bool $isActive): array
    {
        return DB::transaction(function () use ($target, $isActive): array {
            if (! $isActive && $target->role === Role::Owner) {
                $this->assertAnotherOwnerRemains($target);
            }

            $target->forceFill(['is_active' => $isActive])->save();

            if ($isActive) {
                return ['user' => $target, 'future_bookings_count' => 0];
            }

            $futureBookingsCount = Booking::query()
                ->withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $target->business_id)
                ->where('employee_id', $target->id)
                ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
                ->where('starts_at', '>', now())
                ->count();

            $this->revoker->revoke($target);

            return ['user' => $target, 'future_bookings_count' => $futureBookingsCount];
        });
    }

    /**
     * Bloquea TODAS las filas de owners activos del negocio, ordenadas por id.
     *
     * El orden fijo es lo que evita el deadlock entre dos desactivaciones
     * simultáneas: ambas transacciones piden los mismos locks en la misma
     * secuencia, así que la segunda espera en vez de cruzarse con la primera.
     * Al desbloquearse, Postgres re-evalúa la fila contra el WHERE (READ
     * COMMITTED), así que la segunda ya no ve como activo al owner que la
     * primera acaba de desactivar y levanta la excepción.
     */
    private function assertAnotherOwnerRemains(User $target): void
    {
        $activeOwnerIds = User::query()
            ->where('business_id', $target->business_id)
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeOwnerIds->count() <= 1 && $activeOwnerIds->contains($target->id)) {
            throw ValidationException::withMessages([
                'is_active' => 'No podés desactivar al último propietario activo del negocio.',
            ]);
        }
    }
}
