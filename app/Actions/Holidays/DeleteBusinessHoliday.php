<?php

namespace App\Actions\Holidays;

use App\Models\BusinessHoliday;

class DeleteBusinessHoliday
{
    /**
     * Borrar un feriado no valida nada: solo libera disponibilidad.
     */
    public function handle(BusinessHoliday $holiday): void
    {
        $holiday->delete();
    }
}
