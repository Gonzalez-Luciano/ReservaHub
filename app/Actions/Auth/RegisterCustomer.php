<?php

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Models\User;

class RegisterCustomer
{
    public function handle(string $name, string $email, string $password): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'business_id' => null,
            'role' => Role::Customer,
        ]);
    }
}
