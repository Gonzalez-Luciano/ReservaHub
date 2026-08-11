<?php

namespace App\Enums;

enum ReminderType: string
{
    case TwentyFourHours = '24h';
    case TwoHours = '2h';

    public function hoursBefore(): int
    {
        return match ($this) {
            self::TwentyFourHours => 24,
            self::TwoHours => 2,
        };
    }
}
