<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Employee = 'employee';
    case Customer = 'customer';
}
