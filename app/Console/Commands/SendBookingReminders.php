<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\Scopes\BusinessScope;
use App\Notifications\Bookings\BookingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Envía los recordatorios de 24 y 2 horas de las reservas confirmadas.';

    public function handle(): int
    {
        $sent = 0;

        foreach (ReminderType::cases() as $type) {
            foreach ($this->pendingBookings($type) as $booking) {
                if ($this->claim($booking, $type)) {
                    $booking->customer->notify(new BookingReminderNotification($booking, $type));
                    $sent++;
                }
            }
        }

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * @return LazyCollection<int, Booking>
     */
    private function pendingBookings(ReminderType $type)
    {
        $now = now();

        return Booking::withoutGlobalScope(BusinessScope::class)
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addHours($type->hoursBefore()))
            // Sin esta guarda, una reserva creada con poca antelación dispararía
            // los dos recordatorios en la misma corrida.
            ->when(
                $type === ReminderType::TwentyFourHours,
                fn ($query) => $query->where('starts_at', '>', $now->copy()->addHours(ReminderType::TwoHours->hoursBefore())),
            )
            ->whereDoesntHave('reminders', fn ($query) => $query->where('type', $type->value))
            ->with(['business', 'customer', 'employee'])
            ->orderBy('starts_at')
            ->cursor();
    }

    /**
     * Reclama el recordatorio antes de enviarlo. El índice único (booking_id, type)
     * hace que dos corridas simultáneas no puedan reclamar el mismo.
     */
    private function claim(Booking $booking, ReminderType $type): bool
    {
        return DB::table('booking_reminders')->insertOrIgnore([
            'booking_id' => $booking->id,
            'type' => $type->value,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
