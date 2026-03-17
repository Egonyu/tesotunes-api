<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\User;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\ProductCategory;
use App\Modules\Store\Models\Store;
use Illuminate\Database\Seeder;

class LocalStoreCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $artistUser = User::where('email', 'artist@tesotunes.com')->first() ?? User::find(5);
        $artist = Artist::where('user_id', $artistUser?->id)->first() ?? Artist::find(1);
        $listenerUser = User::where('email', 'user@tesotunes.com')->first() ?? User::find(4);

        if (! $artistUser || ! $artist || ! $listenerUser) {
            $this->command?->warn('LocalStoreCatalogSeeder skipped: expected demo artist/user accounts were not found.');

            return;
        }

        $categories = collect([
            [
                'slug' => 'merchandise',
                'name' => 'Merchandise',
                'description' => 'Artist merch, apparel, and collectibles.',
                'icon' => 'shopping-bag',
                'sort_order' => 1,
            ],
            [
                'slug' => 'digital-goods',
                'name' => 'Digital Goods',
                'description' => 'Sample packs, downloads, and exclusive digital releases.',
                'icon' => 'download',
                'sort_order' => 2,
            ],
            [
                'slug' => 'fan-experiences',
                'name' => 'Fan Experiences',
                'description' => 'Meetups, shoutouts, and special artist experiences.',
                'icon' => 'sparkles',
                'sort_order' => 3,
            ],
        ])->mapWithKeys(function (array $attributes) {
            $category = ProductCategory::updateOrCreate(
                ['slug' => $attributes['slug']],
                [
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'icon' => $attributes['icon'],
                    'sort_order' => $attributes['sort_order'],
                    'is_active' => true,
                ]
            );

            return [$attributes['slug'] => $category];
        });

        $artistStore = Store::updateOrCreate(
            ['slug' => 'dj-tesobeats-eduka'],
            [
                'user_id' => $artistUser->id,
                'owner_type' => Artist::class,
                'owner_id' => $artist->id,
                'name' => 'DJ TesoBeats Eduka',
                'description' => 'Official merch, fan experiences, and exclusive drops from DJ TesoBeats.',
                'email' => $artistUser->email,
                'phone' => '256772123456',
                'city' => 'Soroti',
                'country' => 'Uganda',
                'store_type' => Store::TYPE_ARTIST,
                'subscription_tier' => Store::TIER_PREMIUM,
                'status' => Store::STATUS_ACTIVE,
                'is_verified' => true,
                'offers_local_pickup' => true,
                'pickup_address' => 'TesoTunes Hub, Soroti',
                'metadata' => [
                    'brand_story' => 'Afrobeats energy with Teso roots.',
                ],
            ]
        );

        $listenerStore = Store::updateOrCreate(
            ['slug' => 'teso-essentials-eduka'],
            [
                'user_id' => $listenerUser->id,
                'owner_type' => User::class,
                'owner_id' => $listenerUser->id,
                'name' => 'Teso Essentials Eduka',
                'description' => 'Community-curated lifestyle goods and music-inspired essentials.',
                'email' => $listenerUser->email,
                'phone' => '256701234567',
                'city' => 'Kampala',
                'country' => 'Uganda',
                'store_type' => Store::TYPE_USER,
                'subscription_tier' => Store::TIER_FREE,
                'status' => Store::STATUS_ACTIVE,
                'is_verified' => false,
                'offers_local_pickup' => false,
                'metadata' => [
                    'brand_story' => 'Everyday items for fans of East African culture.',
                ],
            ]
        );

        $artistStore->categories()->syncWithoutDetaching([
            $categories['merchandise']->id,
            $categories['fan-experiences']->id,
            $categories['digital-goods']->id,
        ]);

        $listenerStore->categories()->syncWithoutDetaching([
            $categories['merchandise']->id,
            $categories['digital-goods']->id,
        ]);

        $this->seedProduct(
            $artistStore,
            $categories['merchandise'],
            [
                'slug' => 'teso-beats-tour-hoodie',
                'name' => 'Teso Beats Tour Hoodie',
                'description' => 'Heavyweight black hoodie from the Sunrise in Soroti live set.',
                'short_description' => 'Official tour hoodie.',
                'product_type' => Product::TYPE_PHYSICAL,
                'price_ugx' => 85000,
                'price_credits' => 82000,
                'inventory_quantity' => 18,
                'is_featured' => true,
                'featured_image' => 'store-media/products/teso-beats-hoodie-main.svg',
                'images' => [
                    'store-media/products/teso-beats-hoodie-main.svg',
                    'store-media/products/teso-beats-hoodie-back.svg',
                    'store-media/products/teso-beats-hoodie-detail.svg',
                ],
                'metadata' => [
                    'Collection' => 'Sunrise in Soroti',
                    'Material' => 'Heavy cotton fleece',
                    'Fit' => 'Unisex relaxed fit',
                    'Pickup' => 'Soroti hub or delivery',
                ],
            ]
        );

        $this->seedProduct(
            $artistStore,
            $categories['digital-goods'],
            [
                'slug' => 'teso-drum-loop-pack',
                'name' => 'Teso Drum Loop Pack',
                'description' => 'A producer-ready pack of rhythm loops inspired by traditional Teso percussion.',
                'short_description' => 'Exclusive rhythm loops.',
                'product_type' => Product::TYPE_SERVICE,
                'price_ugx' => 25000,
                'price_credits' => 24000,
                'inventory_quantity' => 50,
                'is_featured' => true,
                'featured_image' => 'store-media/products/teso-drum-loop-pack-cover.svg',
                'images' => [
                    'store-media/products/teso-drum-loop-pack-cover.svg',
                    'store-media/products/teso-drum-loop-pack-pattern.svg',
                ],
                'metadata' => [
                    'Format' => '24-bit WAV',
                    'Loops' => '36 royalty-free loops',
                    'Tempo' => '108-128 BPM',
                    'Delivery' => 'Instant download',
                ],
            ]
        );

        $this->seedProduct(
            $artistStore,
            $categories['fan-experiences'],
            [
                'slug' => 'backstage-video-shoutout',
                'name' => 'Backstage Video Shoutout',
                'description' => 'Receive a personalized backstage thank-you video from DJ TesoBeats.',
                'short_description' => 'Personalized fan shoutout.',
                'product_type' => Product::TYPE_EXPERIENCE,
                'price_ugx' => 60000,
                'price_credits' => 55000,
                'inventory_quantity' => 12,
                'is_featured' => false,
                'featured_image' => 'store-media/products/backstage-video-shoutout-card.svg',
                'images' => [
                    'store-media/products/backstage-video-shoutout-card.svg',
                    'store-media/products/backstage-video-shoutout-phone.svg',
                ],
                'metadata' => [
                    'Format' => 'Vertical HD video',
                    'Turnaround' => 'Within 72 hours',
                    'Includes' => 'Name mention and thank-you',
                    'Delivery' => 'Private download link',
                ],
            ]
        );

        $this->seedProduct(
            $listenerStore,
            $categories['merchandise'],
            [
                'slug' => 'teso-tote-bag',
                'name' => 'Teso Community Tote Bag',
                'description' => 'Sturdy everyday tote celebrating East African music culture.',
                'short_description' => 'Community tote bag.',
                'product_type' => Product::TYPE_PHYSICAL,
                'price_ugx' => 30000,
                'price_credits' => 0,
                'inventory_quantity' => 24,
                'is_featured' => false,
                'featured_image' => 'store-media/products/teso-community-tote-bag-main.svg',
                'images' => [
                    'store-media/products/teso-community-tote-bag-main.svg',
                    'store-media/products/teso-community-tote-bag-detail.svg',
                ],
                'metadata' => [
                    'Material' => 'Heavyweight canvas',
                    'Size' => 'Daily carry tote',
                    'Straps' => 'Double stitched handles',
                    'Use' => 'Merch, books, market runs',
                ],
            ]
        );

        $this->seedProduct(
            $listenerStore,
            $categories['digital-goods'],
            [
                'slug' => 'festival-planner-template',
                'name' => 'Festival Planner Template',
                'description' => 'A printable planner for fans organizing festival trips and merch budgets.',
                'short_description' => 'Printable fan planner.',
                'product_type' => Product::TYPE_SERVICE,
                'price_ugx' => 15000,
                'price_credits' => 12000,
                'inventory_quantity' => 100,
                'is_featured' => false,
                'featured_image' => 'store-media/products/festival-planner-template-cover.svg',
                'images' => [
                    'store-media/products/festival-planner-template-cover.svg',
                    'store-media/products/festival-planner-template-spread.svg',
                ],
                'metadata' => [
                    'Format' => 'Printable PDF',
                    'Layouts' => 'A4 and mobile-friendly',
                    'Includes' => 'Budget, itinerary, merch list',
                    'Delivery' => 'Instant download',
                ],
            ]
        );

        $artistStore->update([
            'total_orders' => 12,
            'total_sales_ugx' => 925000,
            'rating' => 4.7,
            'review_count' => 8,
        ]);

        $listenerStore->update([
            'total_orders' => 4,
            'total_sales_ugx' => 180000,
            'rating' => 4.2,
            'review_count' => 3,
        ]);
    }

    protected function seedProduct(Store $store, ProductCategory $category, array $attributes): void
    {
        Product::updateOrCreate(
            ['slug' => $attributes['slug']],
            [
                'store_id' => $store->id,
                'category_id' => $category->id,
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'short_description' => $attributes['short_description'],
                'featured_image' => $attributes['featured_image'] ?? null,
                'images' => $attributes['images'] ?? null,
                'product_type' => $attributes['product_type'],
                'type' => $attributes['product_type'],
                'price_ugx' => $attributes['price_ugx'],
                'price' => $attributes['price_ugx'],
                'price_credits' => $attributes['price_credits'],
                'allow_credit_payment' => $attributes['price_credits'] > 0,
                'allow_hybrid_payment' => $attributes['price_credits'] > 0,
                'inventory_quantity' => $attributes['inventory_quantity'],
                'stock_quantity' => $attributes['inventory_quantity'],
                'track_inventory' => true,
                'allow_backorder' => false,
                'low_stock_threshold' => 5,
                'status' => Product::STATUS_ACTIVE,
                'is_active' => true,
                'published_at' => now(),
                'is_featured' => $attributes['is_featured'],
                'average_rating' => 4.5,
                'review_count' => 2,
                'metadata' => array_merge(
                    ['source' => 'local_store_catalog_seed'],
                    $attributes['metadata'] ?? []
                ),
            ]
        );
    }
}
