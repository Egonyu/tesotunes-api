<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;

class RealWorldStoreSeeder extends Seeder
{
    /**
     * Seed the application's database with real-world Uganda music industry data.
     */
    public function run(): void
    {
        $this->command->info('🌍 Seeding Real-World Uganda Music Store Data...');
        $this->command->newLine();

        // Step 1: Categories (if not already seeded)
        $this->command->info('📂 Step 1/3: Seeding product categories...');
        $this->call(StoreCategoriesSeeder::class);
        
        // Step 2: Create realistic stores with actual artist names
        $this->command->info('🏪 Step 2/3: Creating real-world artist stores...');
        $this->call(RealWorldStoresSeeder::class);
        
        // Step 3: Create realistic products with Uganda pricing
        $this->command->info('📦 Step 3/3: Creating real-world products...');
        $this->call(RealWorldProductsSeeder::class);

        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('✅ REAL-WORLD STORE DATA SEEDING COMPLETE!');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->newLine();
        
        $this->printSummary();
    }

    /**
     * Print seeding summary
     */
    private function printSummary(): void
    {
        $categoriesCount = \App\Modules\Store\Models\ProductCategory::count();
        $storesCount = \App\Modules\Store\Models\Store::count();
        $productsCount = \App\Modules\Store\Models\Product::count();
        $usersCount = \App\Models\User::count();

        $this->command->table(
            ['Resource', 'Count'],
            [
                ['Users (Artists)', $usersCount],
                ['Product Categories', $categoriesCount],
                ['Artist Stores', $storesCount],
                ['Products', $productsCount],
            ]
        );

        $this->command->newLine();
        $this->command->info('🎭 Featured Stores:');
        $this->command->line('  • Navio Official Store (Hip Hop Merchandise)');
        $this->command->line('  • Nessim Production House (Premium Beats)');
        $this->command->line('  • Kampala Merch Co. (Multi-Artist Merch)');
        $this->command->line('  • Swangz Avenue Studios (Studio Services)');
        $this->command->line('  • Eddy Kenzo Experiences (VIP Access)');
        $this->command->line('  • Cindy Sanyu Official (Dancehall Queen)');
        $this->command->line('  • Artin Pro Beats (Affordable Beats)');
        $this->command->line('  • Fenon Records Studio (Legendary Studio)');
        $this->command->line('  • Bebe Cool Store (Gagamel Gang)');
        $this->command->line('  • Levixone Gospel Store (Gospel Music)');

        $this->command->newLine();
        $this->command->info('💰 Pricing Examples (UGX):');
        $this->command->line('  • T-Shirts: 35,000 - 45,000 (~$10-12)');
        $this->command->line('  • Hoodies: 85,000 - 95,000 (~$23-26)');
        $this->command->line('  • Beats (Non-exclusive): 100,000 - 180,000 (~$27-49)');
        $this->command->line('  • Beats (Exclusive): 280,000 - 800,000 (~$76-217)');
        $this->command->line('  • Studio Services: 250,000 - 500,000 (~$68-136)');
        $this->command->line('  • VIP Experiences: 500,000 - 1,500,000 (~$136-408)');

        $this->command->newLine();
        $this->command->info('🚀 Quick Start:');
        $this->command->line('  Frontend: http://music.test/store');
        $this->command->line('  Backend:  http://music.test/admin/store');
        
        $this->command->newLine();
        $this->command->info('🔐 Default Artist Login Credentials:');
        $this->command->line('  Email: navio@lineone.ug (or any artist email)');
        $this->command->line('  Password: password123');

        $this->command->newLine();
    }
}
