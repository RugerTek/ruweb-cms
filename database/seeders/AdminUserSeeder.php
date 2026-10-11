<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Username;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::where('email', config('cms.default_admin.email'))->exists()) {
            $username = Username::normalize(config('cms.default_admin.username'));
            Validator::make(['username' => $username], ['username' => Username::rules()])->validate();
            User::create([
                'email' => config('cms.default_admin.email'),
                'username' => $username,
                'name' => config('cms.default_admin.name'),
                'password' => Hash::make(config('cms.default_admin.password')),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
        }

        // Backfill any existing users that were created before email verification
        // was enforced, so sign-in isn't blocked by unverified state.
        User::whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }
}
