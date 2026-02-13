<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Store\Models\{Store, Product, ProductCategory};
use Illuminate\Support\Str;

class SampleProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stores = Store::where('status', 'active')->get();
        
        if ($stores->isEmpty()) {
            $this->command->error('No active stores found. Run SampleStoresSeeder first.');
            return;
        }

        // Get categories
        $merchCategory = ProductCategory::where('slug', 'merchandise')->first();
        $digitalCategory = ProductCategory::where('slug', 'digital-products')->first();
        $servicesCategory = ProductCategory::where('slug', 'services')->first();
        $experiencesCategory = ProductCategory::where('slug', 'experiences')->first();

        // Physical products (Merchandise)
        $merchProducts = [
            [
                'name' => 'Classic Black T-Shirt',
                'description' => 'Premium quality 100% cotton t-shirt with artist logo. Available in sizes S-XXL.',
                'product_type' => 'physical',
                'price_ugx' => 35000,
                'price_credits' => 32000,
                'inventory_quantity' => 50,
                'track_inventory' => true,
                'sku' => 'TSH-BLK-001',
            ],
            [
                'name' => 'Pullover Hoodie - Grey',
                'description' => 'Comfortable pullover hoodie with front pocket. Perfect for Kampala weather.',
                'product_type' => 'physical',
                'price_ugx' => 75000,
                'price_credits' => 70000,
                'inventory_quantity' => 30,
                'sku' => 'HOD-GRY-001',
            ],
            [
                'name' => 'Snapback Cap',
                'description' => 'Adjustable snapback cap with embroidered logo. One size fits all.',
                'product_type' => 'physical',
                'price_ugx' => 25000,
                'price_credits' => 23000,
                'inventory_quantity' => 100,
                'sku' => 'CAP-SNP-001',
            ],
        ];

        // Digital products (Beats & Samples)
        $digitalProducts = [
            [
                'name' => 'Afrobeat Producer Pack',
                'description' => 'Professional Afrobeat production pack with 10 beats, 50 samples, and MIDI files. Instant download.',
                'product_type' => 'digital',
                'price_ugx' => 150000,
                'price_credits' => 140000,
                'inventory_quantity' => null, // Digital = unlimited
                'sku' => 'BEAT-AFR-001',
            ],
            [
                'name' => 'Dancehall Beat - "Fire"',
                'description' => 'High-energy dancehall beat. Exclusive rights available. WAV + MP3 + stems included.',
                'product_type' => 'digital',
                'price_ugx' => 200000,
                'price_credits' => 180000,
                'inventory_quantity' => null,
                'sku' => 'BEAT-DNC-002',
            ],
            [
                'name' => 'Drum Kit - Uganda Edition',
                'description' => '200+ authentic African drum samples. Compatible with FL Studio, Logic, Ableton.',
                'product_type' => 'digital',
                'price_ugx' => 80000,
                'price_credits' => 75000,
                'inventory_quantity' => null,
                'sku' => 'SMPL-DRM-001',
            ],
        ];

        // Services
        $serviceProducts = [
            [
                'name' => 'Professional Mixing & Mastering',
                'description' => 'Get your track professionally mixed and mastered by award-winning engineers. Turnaround: 3-5 days.',
                'product_type' => 'service',
                'price_ugx' => 300000,
                'price_credits' => 280000,
                'inventory_quantity' => null,
                'sku' => 'SRV-MIX-001',
            ],
            [
                'name' => 'Beat Production Session',
                'description' => '2-hour studio session with professional producer. Create your custom beat from scratch.',
                'product_type' => 'service',
                'price_ugx' => 250000,
                'price_credits' => 230000,
                'inventory_quantity' => null,
                'sku' => 'SRV-PROD-001',
            ],
            [
                'name' => 'Music Consultation - 1 Hour',
                'description' => 'One-on-one consultation on music career, branding, and industry navigation.',
                'product_type' => 'service',
                'price_ugx' => 100000,
                'price_credits' => 90000,
                'inventory_quantity' => null,
                'sku' => 'SRV-CONS-001',
            ],
        ];

        // Experiences
        $experienceProducts = [
            [
                'name' => 'Meet & Greet Experience',
                'description' => '30-minute private meet & greet with the artist. Photo opportunities and signed merchandise included.',
                'product_type' => 'experience',
                'price_ugx' => 500000,
                'price_credits' => 450000,
                'inventory_quantity' => 5, // Limited slots
                'sku' => 'EXP-MGT-001',
            ],
            [
                'name' => 'Studio Visit & Tour',
                'description' => 'Exclusive behind-the-scenes studio tour. Watch recording sessions and learn production secrets.',
                'product_type' => 'experience',
                'price_ugx' => 350000,
                'price_credits' => 320000,
                'inventory_quantity' => 10,
                'sku' => 'EXP-STD-001',
            ],
            [
                'name' => 'Video Call Session - 30 min',
                'description' => 'Private video call with the artist. Ask questions, get advice, or just chat!',
                'product_type' => 'experience',
                'price_ugx' => 200000,
                'price_credits' => 180000,
                'inventory_quantity' => 20,
                'sku' => 'EXP-VID-001',
            ],
        ];

        // Create products for each store
        foreach ($stores as $index => $store) {
            // Assign different product types to different stores
            switch ($index % 4) {
                case 0: // Merchandise store
                    $products = $merchProducts;
                    $category = $merchCategory;
                    break;
                case 1: // Digital store
                    $products = $digitalProducts;
                    $category = $digitalCategory;
                    break;
                case 2: // Services store
                    $products = $serviceProducts;
                    $category = $servicesCategory;
                    break;
                case 3: // Experiences store
                    $products = $experienceProducts;
                    $category = $experiencesCategory;
                    break;
            }

            foreach ($products as $productData) {
                Product::create([
                    'uuid' => Str::uuid(),
                    'store_id' => $store->id,
                    'category_id' => $category->id ?? null,
                    'name' => $productData['name'],
                    'slug' => Str::slug($productData['name']),
                    'description' => $productData['description'],
                    'short_description' => Str::limit($productData['description'], 100),
                    'product_type' => $productData['product_type'],
                    'price_ugx' => $productData['price_ugx'],
                    'price_credits' => $productData['price_credits'],
                    'allow_credit_payment' => true,
                    'allow_hybrid_payment' => true,
                    'sku' => $productData['sku'],
                    'inventory_quantity' => $productData['inventory_quantity'],
                    'track_inventory' => $productData['inventory_quantity'] !== null,
                    'status' => 'active',
                    'is_featured' => rand(0, 1) === 1,
                    'metadata' => json_encode([
                        'weight' => rand(100, 1000),
                        'dimensions' => '30x20x5 cm',
                        'requires_shipping' => $productData['product_type'] === 'physical',
                    ]),
                ]);
            }
        }

        $this->command->info('✓ Created ' . Product::count() . ' sample products');
    }
}
