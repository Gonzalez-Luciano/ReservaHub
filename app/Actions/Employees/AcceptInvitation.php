<?php

namespace App\Actions\Employees;

use App\Enums\Role;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvitation
{
    public function handle(EmployeeInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'business_id' => $invitation->business_id,
                'role' => Role::Employee,
                'email_verified_at' => now(),
            ]);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });
    }
}
