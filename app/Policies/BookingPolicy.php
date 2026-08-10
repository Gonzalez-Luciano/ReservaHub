<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;

class BookingPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return in_array($user->role, Role::businessStaff(), true) && $user->business_id === $business->id;
    }

    public function view(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->id
            || ($user->business_id !== null && $user->business_id === $booking->business_id);
    }

    public function createByStaff(User $user, Business $business): bool
    {
        return in_array($user->role, Role::businessStaff(), true) && $user->business_id === $business->id;
    }

    public function createByCustomer(User $user): bool
    {
        return $user->role === Role::Customer;
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->cancelOrReschedule($user, $booking);
    }

    public function reschedule(User $user, Booking $booking): bool
    {
        return $this->cancelOrReschedule($user, $booking);
    }

    public function confirm(User $user, Booking $booking): bool
    {
        return $this->isStaffOfBooking($user, $booking);
    }

    public function complete(User $user, Booking $booking): bool
    {
        return $this->isStaffOfBooking($user, $booking);
    }

    public function markNoShow(User $user, Booking $booking): bool
    {
        return $this->isStaffOfBooking($user, $booking);
    }

    private function cancelOrReschedule(User $user, Booking $booking): bool
    {
        if ($this->isStaffOfBooking($user, $booking)) {
            return true;
        }

        if ($booking->customer_id !== $user->id) {
            return false;
        }

        $business = $booking->business;
        $cutoff = CarbonImmutable::parse($booking->starts_at)->subHours($business->cancellation_hours);

        return CarbonImmutable::now($business->timezone)->lessThanOrEqualTo($cutoff);
    }

    private function isStaffOfBooking(User $user, Booking $booking): bool
    {
        return in_array($user->role, Role::businessStaff(), true) && $user->business_id === $booking->business_id;
    }
}
