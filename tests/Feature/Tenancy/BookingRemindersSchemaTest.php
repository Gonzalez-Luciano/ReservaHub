<?php

namespace Tests\Feature\Tenancy;

use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\BookingReminder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingRemindersSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('notifications'));
    }

    public function test_booking_reminders_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('booking_reminders', [
            'id', 'booking_id', 'type', 'sent_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_a_reminder_belongs_to_its_booking(): void
    {
        $booking = Booking::factory()->create();
        $reminder = BookingReminder::factory()->for($booking)->create([
            'type' => ReminderType::TwentyFourHours,
        ]);

        $this->assertTrue($reminder->booking->is($booking));
        $this->assertSame(ReminderType::TwentyFourHours, $reminder->type);
        $this->assertTrue($booking->reminders()->whereKey($reminder->id)->exists());
    }

    public function test_the_same_reminder_type_cannot_be_stored_twice_for_one_booking(): void
    {
        $booking = Booking::factory()->create();
        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);

        $this->expectException(QueryException::class);

        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);
    }

    public function test_both_reminder_types_can_coexist_for_one_booking(): void
    {
        $booking = Booking::factory()->create();

        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwentyFourHours]);
        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);

        $this->assertSame(2, $booking->reminders()->count());
    }

    public function test_deleting_the_booking_deletes_its_reminders(): void
    {
        $booking = Booking::factory()->create();
        BookingReminder::factory()->for($booking)->create(['type' => ReminderType::TwoHours]);

        $booking->delete();

        $this->assertSame(0, BookingReminder::count());
    }

    public function test_hours_before_maps_each_type(): void
    {
        $this->assertSame(24, ReminderType::TwentyFourHours->hoursBefore());
        $this->assertSame(2, ReminderType::TwoHours->hoursBefore());
    }
}
