<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;

/**
 * Espeja la propiedad de BookingPolicy: el pago de una reserva lo ve y lo inicia
 * quien puede ver esa reserva — su cliente, o el staff del negocio dueño.
 */
class PaymentPolicy
{
    public function viewAny(User $user, Booking $booking): bool
    {
        return $this->ownsOrStaffs($user, $booking);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->ownsOrStaffs($user, $payment->booking);
    }

    public function create(User $user, Booking $booking): bool
    {
        return $this->ownsOrStaffs($user, $booking);
    }

    private function ownsOrStaffs(User $user, Booking $booking): bool
    {
        if ($booking->customer_id === $user->id) {
            return true;
        }

        return in_array($user->role, Role::businessStaff(), true)
            && $user->business_id === $booking->business_id;
    }
}
