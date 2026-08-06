<?php

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterBusinessOwner
{
    public function handle(string $name, string $email, string $password, string $businessName): User
    {
        return DB::transaction(function () use ($name, $email, $password, $businessName) {
            $business = Business::create([
                'name' => $businessName,
                'slug' => $this->uniqueSlug($businessName),
            ]);

            return User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'business_id' => $business->id,
                'role' => Role::Owner,
            ]);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Business::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
