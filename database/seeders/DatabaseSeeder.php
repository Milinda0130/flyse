<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the first owner account from OWNER_* environment variables.
     * If OWNER_PASSWORD is empty a random password is generated and printed once.
     */
    public function run(): void
    {
        $email = strtolower((string) env('OWNER_EMAIL', 'owner@agency.test'));
        if (User::where('email', $email)->exists()) {
            $this->command?->info("Owner account {$email} already exists.");

            return;
        }

        $password = (string) env('OWNER_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        User::create([
            'name' => env('OWNER_NAME', 'Agency Owner'),
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        $this->command?->info("Owner account created: {$email}");
        if ($generated) {
            $this->command?->warn("Generated password (shown once, change it after first login): {$password}");
        }
    }
}
