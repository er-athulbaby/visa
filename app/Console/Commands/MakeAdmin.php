<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'visa:make-admin {email}';

    protected $description = 'Give an existing user the admin role (create them first with make:filament-user)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => Role::Admin, 'is_active' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
