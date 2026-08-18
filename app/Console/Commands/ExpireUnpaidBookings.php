<?php

namespace App\Console\Commands;

use App\Actions\Bookings\CancelBooking;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireUnpaidBookings extends Command
{
    protected $signature = 'bookings:expire-unpaid';

    protected $description = 'Cancela las reservas con seña cuya ventana de pago venció sin pago resuelto.';

    public function handle(CancelBooking $cancelBooking): int
    {
        $cancelled = 0;

        $candidates = Booking::withoutGlobalScope(BusinessScope::class)
            ->where('status', BookingStatus::Pending)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidates as $bookingId) {
            $cancelled += DB::transaction(function () use ($bookingId, $cancelBooking) {
                // Orden de bloqueo global: bookings antes que payments.
                $booking = Booking::withoutGlobalScope(BusinessScope::class)
                    ->lockForUpdate()
                    ->find($bookingId);

                if ($booking === null || $booking->status !== BookingStatus::Pending) {
                    return 0;
                }

                if ($booking->payment_expires_at === null || $booking->payment_expires_at->isFuture()) {
                    return 0;
                }

                $statuses = $booking->payments()
                    ->withoutGlobalScope(BusinessScope::class)
                    ->lockForUpdate()
                    ->pluck('status');

                // Un pago aprobado manda: la reserva puede estar por confirmarse.
                if ($statuses->contains(PaymentStatus::Approved)) {
                    return 0;
                }

                // Con un intento vivo, la verdad la tiene el proveedor: espera a
                // que la reconciliación lo resuelva antes de liberar el turno.
                // Un proveedor caído deja el turno bloqueado, que es preferible a
                // cancelar una reserva que quizá ya fue pagada.
                if ($statuses->contains(PaymentStatus::Pending)) {
                    return 0;
                }

                $cancelBooking->handle($booking, null, CancellationReason::PaymentWindowExpired);

                return 1;
            });
        }

        $this->info("Reservas canceladas por seña impaga: {$cancelled}.");

        return self::SUCCESS;
    }
}
