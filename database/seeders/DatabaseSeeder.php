<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            UserSeeder::class,
            GenreSeeder::class,
        ]);

        // Only run seeders for existing tables
        if (Schema::hasTable('moods')) {
            $this->call([MoodSeeder::class]);
        }

        if (Schema::hasTable('credit_rates')) {
            $this->call([CreditRateSeeder::class]);
        }

        if (Schema::hasTable('settings')) {
            $this->call([SettingsSeeder::class]);
        }

        // Always run test data last
        $this->call([TestDataSeeder::class]);

        // Comprehensive data for full-featured testing
        $this->call([ComprehensiveTestDataSeeder::class]);
    }
}
