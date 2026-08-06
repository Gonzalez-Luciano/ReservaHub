<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Employee = 'employee';
    case Customer = 'customer';

    /**
     * Roles that act as business staff (owner, admin, employee) — i.e. can
     * be granted business context / dashboard access, as opposed to Customer.
     *
     * @return array<int, self>
     */
    public static function businessStaff(): array
    {
        return [self::Owner, self::Admin, self::Employee];
    }

    /**
     * Roles that can manage a business (settings, users) — a narrower set
     * than businessStaff(): excludes Employee.
     *
     * @return array<int, self>
     */
    public static function managers(): array
    {
        return [self::Owner, self::Admin];
    }
}
