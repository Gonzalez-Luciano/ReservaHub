<?php

namespace App\Enums;

/**
 * Códigos ISO-4217 soportados por ReservaHub.
 *
 * Set acotado a propósito: validar solo "tres letras" aceptaría `ABC`, y traer
 * la tabla ISO-4217 completa como dependencia es peso de mantenimiento para un
 * catálogo que este proyecto no necesita. Agregar una moneda es una línea.
 */
enum Currency: string
{
    case ARS = 'ARS';
    case BRL = 'BRL';
    case CLP = 'CLP';
    case COP = 'COP';
    case EUR = 'EUR';
    case MXN = 'MXN';
    case PEN = 'PEN';
    case USD = 'USD';
    case UYU = 'UYU';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
