<?php

namespace App\Actions\Account;

use App\Models\User;

class UpdateProfile
{
    public function handle(User $user, string $name, string $email): User
    {
        $emailChanged = $user->email !== $email;

        $user->fill(['name' => $name, 'email' => $email]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }
}
