<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;

class StoreModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🏪 Seeding Store Module...');
        
        $this->call([
            StoreCategoriesSeeder::class,
            SampleStoresSeeder::class,
            SampleProductsSeeder::class,
        ]);
        
        $this->command->info('✅ Store Module seeding complete!');
    }
}
