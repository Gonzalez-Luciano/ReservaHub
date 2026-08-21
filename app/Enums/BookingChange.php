<?php

namespace App\Enums;

use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingNoShow;
use App\Events\BookingRescheduled;

/**
 * Los seis valores que viajan al navegador en el campo `change` de
 * BookingChanged. Es un contrato de cable, no un estado: `rescheduled` no es
 * un BookingStatus, y `created` puede terminar en `pending` o en `confirmed`
 * según la seña. Por eso no se reutiliza BookingStatus.
 */
enum BookingChange: string
{
    case Created = 'created';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /**
     * El match no tiene `default` a propósito: sumar un evento al listener sin
     * mapearlo acá es un UnhandledMatchError inmediato, no un broadcast
     * silencioso con un valor equivocado.
     */
    public static function forEvent(object $event): self
    {
        return match ($event::class) {
            BookingCreated::class => self::Created,
            BookingConfirmed::class => self::Confirmed,
            BookingCancelled::class => self::Cancelled,
            BookingRescheduled::class => self::Rescheduled,
            BookingCompleted::class => self::Completed,
            BookingNoShow::class => self::NoShow,
        };
    }
}
