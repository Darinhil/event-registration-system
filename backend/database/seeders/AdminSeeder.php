<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /** Local installs get a known login. Production must name its own, and never resets an existing admin. */
    public function run(): void
    {
        if (! app()->environment('production')) {
            User::updateOrCreate(['email' => 'admin@example.com'], ['name' => 'Event Admin', 'role' => 'admin', 'password' => 'password']);
            return;
        }

        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        if (! $email || ! $password) throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD before seeding in production.');
        User::firstOrCreate(['email' => $email], ['name' => 'Master Admin', 'role' => 'admin', 'password' => $password]);
    }
}
