<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!config('store.enabled', false)) {
            $this->command->warn('⚠ Store module is disabled. Skipping seeding.');
            return;
        }

        $this->command->info('Seeding Store module data...');
        
        // Seed product categories
        $this->call(ProductCategorySeeder::class);
        
        $this->command->info('✓ Store module seeded successfully');
    }
}
