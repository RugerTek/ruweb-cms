<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CmsResetPasswordCommand extends Command
{
    protected $signature = 'cms:user:reset-password {email?}';
    protected $description = 'Reset an admin/editor password from the CLI';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('User email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('User not found.');

            return Command::FAILURE;
        }

        $password = $this->secret('New password');

        if (! $password || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return Command::FAILURE;
        }

        $user->fill([
            'password' => Hash::make($password),
        ]);

        app(\App\Services\Security\RevokeCredentials::class)->all($user);

        $this->info("Password updated for {$user->email}.");

        return Command::SUCCESS;
    }
}
