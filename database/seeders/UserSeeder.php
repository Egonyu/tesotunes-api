<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create Super Admin
        User::updateOrCreate(
            ['email' => 'admin@tesotunes.com'],
            [
                'name' => 'TesoTunes Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'email_verified_at' => now(),
                'country' => 'UG',
                'is_active' => true,
                'status' => 'active',
            ]
        );

        // Create a test user
        User::updateOrCreate(
            ['email' => 'user@tesotunes.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'role' => 'user',
                'email_verified_at' => now(),
                'country' => 'UG',
                'is_active' => true,
                'status' => 'active',
            ]
        );

        // Create a test artist user
        User::updateOrCreate(
            ['email' => 'artist@tesotunes.com'],
            [
                'name' => 'Test Artist',
                'password' => Hash::make('password'),
                'role' => 'artist',
                'email_verified_at' => now(),
                'country' => 'UG',
                'is_active' => true,
                'status' => 'active',
            ]
        );
    }
}
