<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            UserSeeder::class,
            GenreSeeder::class,
            MoodSeeder::class,
            CreditRateSeeder::class,
            SettingsSeeder::class,
            TestDataSeeder::class,
            PollSeeder::class,
        ]);
    }
}
