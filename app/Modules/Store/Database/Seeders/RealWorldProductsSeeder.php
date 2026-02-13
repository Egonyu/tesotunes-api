<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Store\Models\{Store, Product, ProductCategory};
use Illuminate\Support\Str;

class RealWorldProductsSeeder extends Seeder
{
    /**
     * Run the database seeds - Real world Uganda music products & pricing
     */
    public function run(): void
    {
        $stores = Store::where('status', 'active')->get();
        
        if ($stores->isEmpty()) {
            $this->command->error('No active stores found. Run RealWorldStoresSeeder first.');
            return;
        }

        // Get categories
        $categories = [
            'merchandise' => ProductCategory::where('slug', 'merchandise')->first(),
            'tshirts' => ProductCategory::where('slug', 'tshirts')->first(),
            'hoodies' => ProductCategory::where('slug', 'hoodies')->first(),
            'digital' => ProductCategory::where('slug', 'digital-products')->first(),
            'beats' => ProductCategory::where('slug', 'beats')->first(),
            'services' => ProductCategory::where('slug', 'services')->first(),
            'mixing' => ProductCategory::where('slug', 'mixing-mastering')->first(),
            'experiences' => ProductCategory::where('slug', 'experiences')->first(),
            'meetgreet' => ProductCategory::where('slug', 'meet-greet')->first(),
        ];

        // NAVIO OFFICIAL STORE - Merchandise
        $navioStore = $stores->where('slug', 'navio-official')->first();
        if ($navioStore) {
            $this->createNavioProducts($navioStore, $categories);
        }

        // NESSIM BEATS - Digital Products
        $nessimStore = $stores->where('slug', 'nessim-beats')->first();
        if ($nessimStore) {
            $this->createNessimProducts($nessimStore, $categories);
        }

        // KAMPALA MERCH - Merchandise
        $kampalaStore = $stores->where('slug', 'kampala-merch')->first();
        if ($kampalaStore) {
            $this->createKampalaMerchProducts($kampalaStore, $categories);
        }

        // SWANGZ STUDIOS - Services
        $swangzStore = $stores->where('slug', 'swangz-studios')->first();
        if ($swangzStore) {
            $this->createSwangzProducts($swangzStore, $categories);
        }

        // EDDY KENZO - Experiences
        $kenzoStore = $stores->where('slug', 'eddy-kenzo-vip')->first();
        if ($kenzoStore) {
            $this->createKenzoProducts($kenzoStore, $categories);
        }

        // CINDY SANYU - Mixed
        $cindyStore = $stores->where('slug', 'cindy-official')->first();
        if ($cindyStore) {
            $this->createCindyProducts($cindyStore, $categories);
        }

        // ARTIN PRO - Beats
        $artinStore = $stores->where('slug', 'artin-pro')->first();
        if ($artinStore) {
            $this->createArtinProducts($artinStore, $categories);
        }

        // FENON RECORDS - Services
        $fenonStore = $stores->where('slug', 'fenon-records')->first();
        if ($fenonStore) {
            $this->createFenonProducts($fenonStore, $categories);
        }

        // BEBE COOL - Merchandise
        $bebeStore = $stores->where('slug', 'bebe-cool-store')->first();
        if ($bebeStore) {
            $this->createBebeProducts($bebeStore, $categories);
        }

        $this->command->info('✓ Created ' . Product::count() . ' real-world products');
    }

    private function createNavioProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Navio "King of Hip Hop" T-Shirt - Black',
                'description' => 'Premium 100% cotton t-shirt with iconic Navio branding. Features the "King of Hip Hop" crown logo. Available in sizes S to XXL. Proudly Made in Uganda.',
                'product_type' => 'physical',
                'price_ugx' => 40000, // ~$11 USD
                'price_credits' => 36000,
                'inventory_quantity' => 75,
                'sku' => 'NAV-TSH-BLK-001',
                'category' => $categories['tshirts'],
            ],
            [
                'name' => 'Navio Hoodie - "More Fire" Edition',
                'description' => 'Limited edition pullover hoodie from Navio\'s "More Fire" album era. Heavy cotton blend, perfect for Kampala evenings. Front pocket with embroidered logo.',
                'product_type' => 'physical',
                'price_ugx' => 95000, // ~$26 USD
                'price_credits' => 88000,
                'inventory_quantity' => 45,
                'sku' => 'NAV-HOD-GRY-001',
                'category' => $categories['hoodies'],
            ],
            [
                'name' => 'Navio Snapback Cap - Uganda Flag Colors',
                'description' => 'Adjustable snapback cap in Uganda flag colors (Black, Yellow, Red). Embroidered Navio logo. One size fits all.',
                'product_type' => 'physical',
                'price_ugx' => 35000, // ~$10 USD
                'price_credits' => 32000,
                'inventory_quantity' => 120,
                'sku' => 'NAV-CAP-UGA-001',
                'category' => $categories['merchandise'],
            ],
            [
                'name' => 'Signed "Pride of Africa" Album CD',
                'description' => 'Physical CD signed by Navio himself. Includes 15 tracks from the acclaimed "Pride of Africa" album. Collector\'s item with authenticity certificate.',
                'product_type' => 'physical',
                'price_ugx' => 60000, // ~$16 USD
                'price_credits' => 55000,
                'inventory_quantity' => 25,
                'sku' => 'NAV-CD-POA-001',
                'category' => $categories['merchandise'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createNessimProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Afrobeat Production Pack - "Kampala Vibes"',
                'description' => 'Professional Afrobeat production kit with 15 beats, 100+ samples, drum kits, and MIDI files. Includes hits in the style of songs by Sheebah, Winnie Nwagi. Full commercial rights included.',
                'product_type' => 'digital',
                'price_ugx' => 280000, // ~$76 USD
                'price_credits' => 260000,
                'inventory_quantity' => 9999, // High number for digital = unlimited
                'sku' => 'NES-BEAT-AFR-001',
                'category' => $categories['beats'],
            ],
            [
                'name' => 'Dancehall Beat - "Boda Boda Riddim"',
                'description' => 'High energy dancehall beat with authentic Uganda street vibes. Perfect for uptempo tracks. WAV + MP3 + trackout stems. Exclusive license available.',
                'product_type' => 'digital',
                'price_ugx' => 350000, // ~$95 USD
                'price_credits' => 320000,
                'inventory_quantity' => 9999,
                'sku' => 'NES-BEAT-DNC-002',
                'category' => $categories['beats'],
            ],
            [
                'name' => 'Afro-Pop Beat - "Mukwano"',
                'description' => 'Smooth Afro-pop instrumental perfect for love songs. Melodic and radio-ready. Used by multiple artists. Non-exclusive license.',
                'product_type' => 'digital',
                'price_ugx' => 180000, // ~$49 USD
                'price_credits' => 165000,
                'inventory_quantity' => 9999,
                'sku' => 'NES-BEAT-POP-003',
                'category' => $categories['beats'],
            ],
            [
                'name' => 'Amapiano Starter Pack',
                'description' => 'Complete Amapiano production bundle. 10 beats, log drum samples, percussion loops, and basslines. Join the Amapiano wave sweeping East Africa.',
                'product_type' => 'digital',
                'price_ugx' => 220000, // ~$60 USD
                'price_credits' => 200000,
                'inventory_quantity' => 9999,
                'sku' => 'NES-AMP-PACK-001',
                'category' => $categories['digital'],
            ],
            [
                'name' => 'Exclusive Beat Custom Production',
                'description' => 'Custom beat produced exclusively for you. Unlimited revisions until perfect. Full ownership rights. 7-10 day delivery. Consultation included.',
                'product_type' => 'service',
                'price_ugx' => 800000, // ~$217 USD
                'price_credits' => 750000,
                'inventory_quantity' => 9999,
                'sku' => 'NES-SRV-CUST-001',
                'category' => $categories['services'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createKampalaMerchProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Uganda Hip Hop T-Shirt - Unisex',
                'description' => 'Celebrate Uganda Hip Hop culture. Features iconic Kampala skyline with turntables. 100% cotton, locally printed. Sizes S-XXL.',
                'product_type' => 'physical',
                'price_ugx' => 35000,
                'price_credits' => 32000,
                'inventory_quantity' => 200,
                'sku' => 'KAM-TSH-HIP-001',
                'category' => $categories['tshirts'],
            ],
            [
                'name' => '"256" Pride Hoodie',
                'description' => 'Show your Uganda pride! 256 area code front and center. Premium heavyweight cotton blend. Perfect for concerts and events.',
                'product_type' => 'physical',
                'price_ugx' => 85000,
                'price_credits' => 78000,
                'inventory_quantity' => 80,
                'sku' => 'KAM-HOD-256-001',
                'category' => $categories['hoodies'],
            ],
            [
                'name' => 'Musician Tote Bag - Canvas',
                'description' => 'Heavy duty canvas tote bag perfect for studio gear or daily use. Features "Support Uganda Music" print. Eco-friendly.',
                'product_type' => 'physical',
                'price_ugx' => 25000,
                'price_credits' => 23000,
                'inventory_quantity' => 150,
                'sku' => 'KAM-BAG-TOT-001',
                'category' => $categories['merchandise'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createSwangzProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Professional Mixing & Mastering',
                'description' => 'World-class mixing and mastering from Uganda\'s #1 studio. Same team behind hits by Vinka, Winnie Nwagi, and Azawi. 5-7 day turnaround. Up to 3 revisions included.',
                'product_type' => 'service',
                'price_ugx' => 500000, // ~$136 USD
                'price_credits' => 470000,
                'inventory_quantity' => 9999,
                'sku' => 'SWA-SRV-MIX-001',
                'category' => $categories['mixing'],
            ],
            [
                'name' => 'Full Song Production Package',
                'description' => 'Complete production from beat to final master. Includes beat production, recording, mixing, and mastering. Studio time with engineer. 2-3 weeks delivery.',
                'product_type' => 'service',
                'price_ugx' => 1500000, // ~$408 USD
                'price_credits' => 1400000,
                'inventory_quantity' => 9999,
                'sku' => 'SWA-SRV-FULL-001',
                'category' => $categories['services'],
            ],
            [
                'name' => 'Studio Recording - 4 Hours',
                'description' => '4-hour studio session with professional engineer. Record up to 2-3 songs (tracking only). Equipment and engineer expertise included.',
                'product_type' => 'service',
                'price_ugx' => 400000, // ~$109 USD
                'price_credits' => 380000,
                'inventory_quantity' => 9999,
                'sku' => 'SWA-SRV-REC-4HR',
                'category' => $categories['services'],
            ],
            [
                'name' => 'Artist Development Consultation',
                'description' => '2-hour strategy session with Swangz Avenue management. Discuss career direction, branding, content strategy. For serious artists only.',
                'product_type' => 'service',
                'price_ugx' => 300000, // ~$82 USD
                'price_credits' => 280000,
                'inventory_quantity' => 9999,
                'sku' => 'SWA-SRV-CONS-2HR',
                'category' => $categories['services'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createKenzoProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Private Meet & Greet with Eddy Kenzo',
                'description' => '45-minute private meet & greet with BET Award winner Eddy Kenzo. Professional photoshoot, autographs, personal conversation. Signed memorabilia included. Limited slots monthly.',
                'product_type' => 'experience',
                'price_ugx' => 1200000, // ~$326 USD
                'price_credits' => 1100000,
                'inventory_quantity' => 4,
                'sku' => 'KEN-EXP-MGT-001',
                'category' => $categories['meetgreet'],
            ],
            [
                'name' => 'Studio Session with Eddy Kenzo',
                'description' => 'Watch Eddy Kenzo record in Big Talent studio. 2-hour session, ask questions, learn his creative process. Extremely limited availability.',
                'product_type' => 'experience',
                'price_ugx' => 1500000, // ~$408 USD
                'price_credits' => 1400000,
                'inventory_quantity' => 2,
                'sku' => 'KEN-EXP-STD-001',
                'category' => $categories['experiences'],
            ],
            [
                'name' => 'Video Call with Eddy Kenzo - 30 Minutes',
                'description' => 'Personal 30-minute video call with Eddy Kenzo. Get advice, discuss music, or just chat with the legend. Perfect gift for fans.',
                'product_type' => 'experience',
                'price_ugx' => 500000, // ~$136 USD
                'price_credits' => 470000,
                'inventory_quantity' => 10,
                'sku' => 'KEN-EXP-VID-30MIN',
                'category' => $categories['experiences'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createCindyProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Cindy "King Herself" T-Shirt - White',
                'description' => 'Official Cindy Sanyu merchandise. Bold "King Herself" print with crown. Premium quality cotton. Show your support for the UMA President.',
                'product_type' => 'physical',
                'price_ugx' => 45000,
                'price_credits' => 42000,
                'inventory_quantity' => 100,
                'sku' => 'CIN-TSH-WHT-001',
                'category' => $categories['tshirts'],
            ],
            [
                'name' => 'Dancehall Masterclass - Video Tutorial',
                'description' => 'Learn from the Queen! 2-hour video masterclass on stage presence, vocal techniques, and dancehall performance. Digital download with lifetime access.',
                'product_type' => 'digital',
                'price_ugx' => 150000,
                'price_credits' => 140000,
                'inventory_quantity' => 9999,
                'sku' => 'CIN-DIG-MAS-001',
                'category' => $categories['digital'],
            ],
            [
                'name' => 'Personal Styling Session - 1 Hour',
                'description' => 'One-on-one styling consultation with Cindy or her stylist. Perfect your stage look and artist image. Video call or in-person (Kampala).',
                'product_type' => 'service',
                'price_ugx' => 400000,
                'price_credits' => 380000,
                'inventory_quantity' => 9999,
                'sku' => 'CIN-SRV-STY-1HR',
                'category' => $categories['services'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createArtinProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Afrobeat - "Sunshine Riddim"',
                'description' => 'Feel-good Afrobeat perfect for summer hits. Uplifting melody, crisp drums. Non-exclusive license. WAV + MP3 included.',
                'product_type' => 'digital',
                'price_ugx' => 120000, // Affordable pricing
                'price_credits' => 110000,
                'inventory_quantity' => 9999,
                'sku' => 'ART-BEAT-SUN-001',
                'category' => $categories['beats'],
            ],
            [
                'name' => 'Trap Beat - "Kampala Streets"',
                'description' => 'Hard-hitting trap beat with 808s. Perfect for rap/hip hop. Gritty and authentic Uganda street sound.',
                'product_type' => 'digital',
                'price_ugx' => 100000,
                'price_credits' => 92000,
                'inventory_quantity' => 9999,
                'sku' => 'ART-BEAT-TRP-002',
                'category' => $categories['beats'],
            ],
            [
                'name' => 'Beat Lease - Any 3 Beats',
                'description' => 'Choose any 3 beats from catalog. Non-exclusive leasing rights. Perfect for mixtapes or singles. Great value bundle.',
                'product_type' => 'digital',
                'price_ugx' => 250000,
                'price_credits' => 230000,
                'inventory_quantity' => 9999,
                'sku' => 'ART-BUNDLE-3BEAT',
                'category' => $categories['beats'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createFenonProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Legendary Studio Session - 8 Hours',
                'description' => 'Full day (8 hours) at Uganda\'s most iconic studio. Same studio used by Jose Chameleone, Bebe Cool, Chameleon. Includes engineer and equipment.',
                'product_type' => 'service',
                'price_ugx' => 800000,
                'price_credits' => 750000,
                'inventory_quantity' => 9999,
                'sku' => 'FEN-SRV-REC-8HR',
                'category' => $categories['services'],
            ],
            [
                'name' => 'Professional Music Video Production',
                'description' => 'Complete music video production. Cinematography, editing, color grading. 3-5 minute video. 2-3 weeks delivery. Portfolio available on request.',
                'product_type' => 'service',
                'price_ugx' => 3000000, // ~$816 USD
                'price_credits' => 2800000,
                'inventory_quantity' => 9999,
                'sku' => 'FEN-SRV-VID-FULL',
                'category' => $categories['services'],
            ],
            [
                'name' => 'Mixing Only - Per Song',
                'description' => 'Professional mixing service only (no mastering). Perfect if you have your own mastering engineer. 3-day turnaround.',
                'product_type' => 'service',
                'price_ugx' => 250000,
                'price_credits' => 235000,
                'inventory_quantity' => 9999,
                'sku' => 'FEN-SRV-MIX-ONLY',
                'category' => $categories['mixing'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    private function createBebeProducts($store, $categories)
    {
        $products = [
            [
                'name' => 'Gagamel Official Jersey',
                'description' => 'Official Gagamel Entertainment soccer-style jersey. Show your support for the Gagamel gang. Premium polyester, moisture-wicking.',
                'product_type' => 'physical',
                'price_ugx' => 65000,
                'price_credits' => 60000,
                'inventory_quantity' => 60,
                'sku' => 'BEB-JER-GAG-001',
                'category' => $categories['merchandise'],
            ],
            [
                'name' => 'Bebe Cool Snapback - Gagamel Logo',
                'description' => 'Official Gagamel snapback cap. Bold logo embroidery. Adjustable strap. Rep the Gagamel movement.',
                'product_type' => 'physical',
                'price_ugx' => 40000,
                'price_credits' => 37000,
                'inventory_quantity' => 85,
                'sku' => 'BEB-CAP-GAG-001',
                'category' => $categories['merchandise'],
            ],
            [
                'name' => 'Signed "Go Mama" Poster',
                'description' => 'Limited edition poster from "Go Mama" era, personally signed by Bebe Cool. Includes certificate of authenticity. Collector\'s item.',
                'product_type' => 'physical',
                'price_ugx' => 80000,
                'price_credits' => 75000,
                'inventory_quantity' => 20,
                'sku' => 'BEB-PST-GM-SIGN',
                'category' => $categories['merchandise'],
            ],
        ];

        $this->createProducts($store, $products);
    }

    /**
     * Helper method to create products
     */
    private function createProducts($store, array $products)
    {
        foreach ($products as $productData) {
            Product::create([
                'uuid' => Str::uuid(),
                'store_id' => $store->id,
                'category_id' => $productData['category']->id ?? null,
                'name' => $productData['name'],
                'slug' => Str::slug($productData['name']),
                'description' => $productData['description'],
                'short_description' => Str::limit($productData['description'], 150),
                'product_type' => $productData['product_type'],
                'price_ugx' => $productData['price_ugx'],
                'price_credits' => $productData['price_credits'],
                'allow_credit_payment' => true,
                'allow_hybrid_payment' => true,
                'sku' => $productData['sku'],
                'inventory_quantity' => $productData['inventory_quantity'],
                'track_inventory' => $productData['inventory_quantity'] !== null,
                'status' => 'active',
                'is_featured' => rand(0, 3) === 0, // 25% chance of featured
                'metadata' => json_encode([
                    'authentic' => true,
                    'made_in_uganda' => $productData['product_type'] === 'physical',
                    'instant_delivery' => $productData['product_type'] === 'digital',
                    'requires_shipping' => $productData['product_type'] === 'physical',
                ]),
            ]);
        }
    }
}
