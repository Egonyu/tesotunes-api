<?php

namespace Database\Seeders;

use App\Models\Review;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\ProductCategory;
use App\Modules\Store\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PromotionMarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('stores') || ! Schema::hasTable('store_products')) {
            $this->command?->warn('PromotionMarketplaceSeeder skipped: required marketplace tables were not found.');

            return;
        }

        $buyer = $this->seedBuyer();
        $category = $this->seedPromotionCategory();

        $promoters = collect($this->promoterDefinitions())->map(function (array $definition) use ($category) {
            $user = $this->seedPromoterUser($definition['user']);
            $store = $this->seedPromoterStore($user, $definition['store']);

            if ($category && Schema::hasTable('store_category_pivot')) {
                $store->categories()->syncWithoutDetaching([$category->id]);
            }

            $products = collect($definition['services'])->map(
                fn (array $service) => $this->seedPromotionProduct($store, $category, $service)
            );

            return compact('user', 'store', 'products');
        })->values();

        $this->seedMarketplaceActivity($buyer, $promoters);

        $this->command?->info('Promotion marketplace seed ready: influencer, DJ, radio, and creator listings are available for local visualization.');
    }

    private function seedBuyer(): User
    {
        return User::updateOrCreate(
            ['email' => 'artist.buyer@tesotunes-test.com'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'Achen Melody',
                'username' => 'achenmelody',
                'first_name' => 'Achen',
                'last_name' => 'Melody',
                'display_name' => 'Achen Melody',
                'password' => Hash::make('password'),
                'role' => 'artist',
                'email_verified_at' => now(),
                'is_active' => true,
                'status' => 'active',
                'country' => 'Uganda',
                'city' => 'Kampala',
                'timezone' => 'Africa/Kampala',
                'language' => 'en',
                'credits' => 250000,
            ]
        );
    }

    private function seedPromotionCategory(): ?ProductCategory
    {
        if (! Schema::hasTable('product_categories')) {
            return null;
        }

        return ProductCategory::updateOrCreate(
            ['slug' => 'promotion-services'],
            [
                'name' => 'Promotion Services',
                'description' => 'Influencer, DJ, radio, and creator promotion offers for artists.',
                'icon' => 'megaphone',
                'sort_order' => 4,
                'is_active' => true,
            ]
        );
    }

    private function seedPromoterUser(array $definition): User
    {
        return User::updateOrCreate(
            ['email' => $definition['email']],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => $definition['name'],
                'username' => $definition['username'],
                'first_name' => $definition['first_name'],
                'last_name' => $definition['last_name'],
                'display_name' => $definition['display_name'],
                'password' => Hash::make('password'),
                'role' => 'user',
                'email_verified_at' => now(),
                'is_active' => true,
                'status' => 'active',
                'country' => $definition['country'],
                'city' => $definition['city'],
                'timezone' => 'Africa/Kampala',
                'language' => 'en',
                'is_verified' => $definition['is_verified'],
                'instagram_url' => $definition['instagram_url'] ?? null,
                'tiktok_url' => $definition['tiktok_url'] ?? null,
                'youtube_url' => $definition['youtube_url'] ?? null,
                'facebook_url' => $definition['facebook_url'] ?? null,
                'twitter_url' => $definition['twitter_url'] ?? null,
            ]
        );
    }

    private function seedPromoterStore(User $user, array $definition): Store
    {
        return Store::updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'user_id' => $user->id,
                'owner_type' => User::class,
                'owner_id' => $user->id,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'email' => $user->email,
                'phone' => $definition['phone'],
                'city' => $definition['city'],
                'country' => $definition['country'],
                'store_type' => Store::TYPE_USER,
                'subscription_tier' => $definition['subscription_tier'] ?? Store::TIER_PREMIUM,
                'status' => Store::STATUS_ACTIVE,
                'is_verified' => $definition['is_verified'],
                'banner' => $definition['banner'],
                'metadata' => [
                    'brand_story' => $definition['brand_story'],
                    'promoter_profile' => [
                        'location' => $definition['location'],
                        'audience_summary' => $definition['audience_summary'],
                        'response_time_hours' => $definition['response_time_hours'],
                        'proof_points' => $definition['proof_points'],
                        'campaign_highlights' => $definition['campaign_highlights'],
                        'portfolio_items' => $definition['portfolio_items'] ?? [],
                        'website_url' => $definition['website_url'] ?? null,
                    ],
                ],
            ]
        );
    }

    private function seedPromotionProduct(Store $store, ?ProductCategory $category, array $definition): Product
    {
        $product = Product::updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'uuid' => Str::uuid()->toString(),
                'store_id' => $store->id,
                'category_id' => $category?->id,
                'name' => $definition['title'],
                'description' => $definition['description'],
                'short_description' => $definition['short_description'],
                'featured_image' => $definition['featured_image'],
                'images' => [$definition['featured_image']],
                'product_type' => Product::TYPE_PROMOTION,
                'type' => Product::TYPE_SERVICE,
                'status' => $definition['status'],
                'is_active' => $definition['status'] === Product::STATUS_ACTIVE,
                'is_featured' => $definition['is_featured'],
                'published_at' => $definition['status'] === Product::STATUS_ACTIVE ? now()->subDays($definition['published_days_ago'] ?? 7) : null,
                'price_credits' => $definition['price_credits'],
                'price_ugx' => $definition['price_ugx'],
                'allow_credit_payment' => $definition['accepts_credits'],
                'allow_hybrid_payment' => $definition['accepts_hybrid'],
                'accepts_credits' => $definition['accepts_credits'],
                'inventory_quantity' => 999,
                'stock_quantity' => 999,
                'track_inventory' => false,
                'allow_backorder' => true,
                'average_rating' => $definition['average_rating'] ?? 0,
                'review_count' => $definition['review_count'] ?? 0,
                'metadata' => [
                    'promotion_type' => $definition['type'],
                    'platform' => $definition['platform'],
                    'estimated_reach' => $definition['estimated_reach'],
                    'audience_niches' => $definition['audience_niches'],
                    'audience_regions' => $definition['audience_regions'],
                    'content_formats' => $definition['content_formats'],
                    'delivery_days_min' => $definition['delivery_days_min'],
                    'delivery_days_max' => $definition['delivery_days_max'],
                    'requirements' => $definition['requirements'],
                    'deliverables' => $definition['deliverables'],
                    'terms' => $definition['terms'],
                    'accepts_ugx' => $definition['accepts_ugx'],
                    'moderation' => [
                        'status' => $definition['status'] === Product::STATUS_ACTIVE ? 'approved' : 'pending',
                        'submitted_at' => now()->subDays(5)->toIso8601String(),
                    ],
                    'platform_capabilities' => $definition['platform_capabilities'] ?? [],
                ],
            ]
        );

        // Product::setTypeAttribute() mirrors `type` into `product_type`,
        // so promotion listings must explicitly restore the marketplace type.
        DB::table('store_products')
            ->where('id', $product->id)
            ->update(['product_type' => Product::TYPE_PROMOTION]);

        return $product->refresh();
    }

    private function seedMarketplaceActivity(User $buyer, $promoters): void
    {
        if (! Schema::hasTable('store_orders') || ! Schema::hasTable('store_order_items')) {
            return;
        }

        $services = [
            $promoters[0]['products'][0],
            $promoters[0]['products'][1],
            $promoters[1]['products'][0],
            $promoters[2]['products'][0],
        ];

        $orders = [
            [
                'order_number' => 'PROMO-SEED-001',
                'product' => $services[0],
                'status' => Order::STATUS_COMPLETED,
                'payment_method' => 'hybrid',
                'verification_status' => 'verified',
                'verification_url' => 'https://www.tiktok.com/@ninawaves/video/sample-track',
                'verification_notes' => 'Video went live with artist tag and challenge CTA.',
                'customer_notes' => 'Push the hook with dance content for Kampala and Nairobi listeners.',
                'days_ago' => 12,
                'review' => [
                    'rating' => 5,
                    'comment' => 'Strong engagement and fast delivery. We saw a clear bump in saves.',
                ],
            ],
            [
                'order_number' => 'PROMO-SEED-002',
                'product' => $services[1],
                'status' => Order::STATUS_PROCESSING,
                'payment_method' => 'credits',
                'verification_status' => 'submitted',
                'verification_url' => 'https://www.instagram.com/reel/sample-campaign',
                'verification_notes' => 'Draft reel link submitted for buyer approval.',
                'customer_notes' => 'Need soft launch content around a new acoustic single.',
                'days_ago' => 3,
            ],
            [
                'order_number' => 'PROMO-SEED-003',
                'product' => $services[2],
                'status' => Order::STATUS_COMPLETED,
                'payment_method' => 'ugx',
                'verification_status' => 'verified',
                'verification_url' => 'https://www.instagram.com/stories/highlights/club-drop',
                'verification_notes' => 'Club spin and flyer recap delivered.',
                'customer_notes' => 'Need a Friday night warm-up spin plus flyer mention.',
                'days_ago' => 8,
                'review' => [
                    'rating' => 4,
                    'comment' => 'Great crowd placement and the recap proof was solid.',
                ],
            ],
            [
                'order_number' => 'PROMO-SEED-004',
                'product' => $services[3],
                'status' => Order::STATUS_REFUNDED,
                'payment_method' => 'ugx',
                'verification_status' => 'rejected',
                'verification_url' => 'https://tesofm.example.com/logs/sample-spin',
                'verification_notes' => 'Spin log was missing the agreed prime-time slot.',
                'customer_notes' => 'The artist asked for evening drive time only.',
                'days_ago' => 6,
                'refund_reason' => 'Prime-time radio slot was not met, refund issued after dispute review.',
                'dispute_reason' => 'Proof submitted did not match the agreed time window.',
            ],
        ];

        foreach ($orders as $definition) {
            $product = $definition['product'];
            $createdAt = now()->subDays($definition['days_ago']);
            $order = Order::updateOrCreate(
                ['order_number' => $definition['order_number']],
                [
                    'uuid' => Str::uuid()->toString(),
                    'store_id' => $product->store_id,
                    'user_id' => $buyer->id,
                    'status' => $definition['status'],
                    'payment_status' => $definition['status'] === Order::STATUS_REFUNDED ? Order::PAYMENT_REFUNDED : Order::PAYMENT_PAID,
                    'fulfillment_status' => $definition['status'] === Order::STATUS_COMPLETED ? 'fulfilled' : 'processing',
                    'payment_method' => $definition['payment_method'],
                    'customer_notes' => $definition['customer_notes'],
                    'subtotal' => $product->price_ugx,
                    'total_amount' => $product->price_ugx,
                    'credit_amount' => $definition['payment_method'] === 'credits' ? $product->price_credits : 0,
                    'subtotal_ugx' => $product->price_ugx,
                    'subtotal_credits' => $definition['payment_method'] === 'credits' ? $product->price_credits : 0,
                    'platform_fee_ugx' => round((float) $product->price_ugx * 0.1, 2),
                    'platform_fee_credits' => $definition['payment_method'] === 'credits' ? (int) round($product->price_credits * 0.1) : 0,
                    'total_ugx' => $product->price_ugx,
                    'total_credits' => $definition['payment_method'] === 'credits' ? $product->price_credits : 0,
                    'paid_ugx' => $definition['payment_method'] === 'credits' ? 0 : $product->price_ugx,
                    'paid_credits' => $definition['payment_method'] === 'credits' ? $product->price_credits : 0,
                    'currency' => 'UGX',
                    'completed_at' => $definition['status'] === Order::STATUS_COMPLETED ? $createdAt->copy()->addDays(4) : null,
                    'refunded_at' => $definition['status'] === Order::STATUS_REFUNDED ? $createdAt->copy()->addDays(3) : null,
                    'refund_reason' => $definition['refund_reason'] ?? null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt->copy()->addDays(1),
                ]
            );

            OrderItem::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                ],
                [
                    'product_snapshot' => $product->toArray(),
                    'product_name' => $product->name,
                    'product_description' => $product->description,
                    'product_image' => $product->featured_image,
                    'product_type' => $product->product_type,
                    'quantity' => 1,
                    'price_ugx' => $product->price_ugx,
                    'price_credits' => $product->price_credits,
                    'payment_method' => $definition['payment_method'],
                    'unit_price' => $product->price_ugx,
                    'subtotal' => $product->price_ugx,
                    'tax_amount' => 0,
                    'total_amount' => $product->price_ugx,
                    'fulfillment_status' => $definition['status'] === Order::STATUS_COMPLETED ? OrderItem::STATUS_FULFILLED : OrderItem::STATUS_PROCESSING,
                    'verification_status' => $definition['verification_status'],
                    'verification_url' => $definition['verification_url'] ?? null,
                    'verification_notes' => $definition['verification_notes'] ?? null,
                    'verification_proof' => json_encode(array_values(array_filter([$definition['verification_url'] ?? null])), JSON_UNESCAPED_SLASHES),
                    'verification_submitted_at' => in_array($definition['verification_status'], ['submitted', 'verified', 'rejected'], true) ? $createdAt->copy()->addDays(2) : null,
                    'verified_at' => $definition['verification_status'] === 'verified' ? $createdAt->copy()->addDays(4) : null,
                    'rejection_reason' => $definition['verification_status'] === 'rejected' ? 'Submission did not satisfy the booked deliverable.' : null,
                    'dispute_reason' => $definition['dispute_reason'] ?? null,
                ]
            );

            if (isset($definition['review']) && Schema::hasTable('reviews')) {
                Review::updateOrCreate(
                    [
                        'user_id' => $buyer->id,
                        'reviewable_type' => Product::class,
                        'reviewable_id' => $product->id,
                    ],
                    [
                        'order_id' => $order->id,
                        'rating' => $definition['review']['rating'],
                        'title' => $definition['review']['title'] ?? null,
                        'content' => $definition['review']['comment'],
                        'status' => Review::STATUS_APPROVED,
                        'is_verified_purchase' => true,
                        'helpful_count' => 3,
                        'metadata' => [
                            'source' => 'promotion_marketplace_seeder',
                            'would_recommend' => ($definition['review']['rating'] ?? 0) >= 4,
                        ],
                    ]
                );
            }
        }
    }

    private function promoterDefinitions(): array
    {
        return [
            [
                'user' => [
                    'email' => 'nina.waves@tesotunes-test.com',
                    'name' => 'Nina Waves',
                    'username' => 'ninawaves',
                    'first_name' => 'Nina',
                    'last_name' => 'Waves',
                    'display_name' => 'Nina Waves',
                    'country' => 'Uganda',
                    'city' => 'Kampala',
                    'is_verified' => true,
                    'followers_count' => 284000,
                    'instagram_url' => 'https://instagram.com/ninawaves',
                    'tiktok_url' => 'https://tiktok.com/@ninawaves',
                ],
                'store' => [
                    'slug' => 'nina-waves-promotions',
                    'name' => 'Nina Waves Promotions',
                    'description' => 'TikTok-first music promotion for artists pushing danceable releases and creator-led hooks.',
                    'phone' => '256700111222',
                    'city' => 'Kampala',
                    'country' => 'Uganda',
                    'is_verified' => true,
                    'banner' => 'store-media/promotions/nina-waves-banner.svg',
                    'brand_story' => 'Creator-led launches for East African music with strong short-form storytelling.',
                    'location' => 'Kampala, Uganda',
                    'audience_summary' => 'Primarily Gen Z listeners across Kampala, Nairobi, and diaspora East African audiences with strong TikTok save intent.',
                    'response_time_hours' => 6,
                    'proof_points' => ['284k TikTok followers', 'Average 92k views per music post', 'Fast-turn campaign recaps'],
                    'campaign_highlights' => ['Launched three Afro-pop hooks into campus creator circles', 'Strong dance challenge conversion for new singles'],
                    'portfolio_items' => [
                        [
                            'title' => 'Afro-pop TikTok hook launch',
                            'summary' => 'Creator-led hook rollout with dance prompt, pinned CTA, and artist tagging for a Kampala-first single.',
                            'outcome' => '118k views and 31 creator duets in 6 days',
                            'platform' => 'tiktok',
                            'asset_url' => 'store-media/promotions/nina-waves-tiktok-launch.svg',
                            'external_url' => 'https://tiktok.com/@ninawaves/video/sample-track',
                        ],
                    ],
                    'website_url' => 'https://ninawaves.tesotunes.test',
                ],
                'services' => [
                    [
                        'slug' => 'nina-waves-tiktok-launch',
                        'title' => 'TikTok Launch Push',
                        'short_description' => 'Launch a song with one high-energy TikTok post plus challenge framing.',
                        'description' => 'A launch package for artists who need an authentic TikTok creator push around a new release. Includes concept planning, one main post, a pinned comment CTA, and recap proof.',
                        'type' => 'influencer_post',
                        'platform' => 'tiktok',
                        'price_credits' => 8500,
                        'price_ugx' => 320000,
                        'accepts_credits' => true,
                        'accepts_ugx' => true,
                        'accepts_hybrid' => true,
                        'estimated_reach' => 90000,
                        'audience_niches' => ['afrobeats', 'dance', 'campus culture'],
                        'audience_regions' => ['Uganda', 'Kenya', 'East Africa Diaspora'],
                        'content_formats' => ['short_video', 'challenge', 'creator_reaction'],
                        'delivery_days_min' => 2,
                        'delivery_days_max' => 5,
                        'deliverables' => ['1 TikTok post', 'Pinned CTA comment', 'Performance recap'],
                        'requirements' => ['action' => 'Share song link and talking points', 'duration_hours' => 48, 'hashtags' => ['#NewMusic', '#TesoTunes']],
                        'terms' => 'One revision on brief alignment before posting. No political or explicit campaigns.',
                        'featured_image' => 'store-media/promotions/nina-waves-tiktok-launch.svg',
                        'status' => Product::STATUS_ACTIVE,
                        'is_featured' => true,
                        'published_days_ago' => 18,
                    ],
                    [
                        'slug' => 'nina-waves-instagram-reel-bundle',
                        'title' => 'Instagram Reel Buzz Bundle',
                        'short_description' => 'Lifestyle-style reel placement for artists who want softer awareness.',
                        'description' => 'A reel-based promo package blending lifestyle storytelling with track placement, ideal for mid-tempo singles, acoustic cuts, and fashion-friendly releases.',
                        'type' => 'reel_campaign',
                        'platform' => 'instagram',
                        'price_credits' => 6200,
                        'price_ugx' => 240000,
                        'accepts_credits' => true,
                        'accepts_ugx' => true,
                        'accepts_hybrid' => false,
                        'estimated_reach' => 54000,
                        'audience_niches' => ['lifestyle', 'afropop', 'fashion'],
                        'audience_regions' => ['Uganda', 'Rwanda', 'Kenya'],
                        'content_formats' => ['reel', 'story', 'behind_the_scenes'],
                        'delivery_days_min' => 3,
                        'delivery_days_max' => 6,
                        'deliverables' => ['1 Reel', '2 Story frames', 'Caption CTA'],
                        'requirements' => ['action' => 'Send cover art, key lyric, and preferred CTA', 'duration_hours' => 72, 'hashtags' => ['#FreshDrop', '#EastAfricanMusic']],
                        'terms' => 'Stories remain up for 24 hours. Reel stays on grid for a minimum of 14 days.',
                        'featured_image' => 'store-media/promotions/nina-waves-instagram-reels.svg',
                        'status' => Product::STATUS_ACTIVE,
                        'is_featured' => false,
                        'published_days_ago' => 12,
                    ],
                ],
            ],
            [
                'user' => [
                    'email' => 'dj.malo@tesotunes-test.com',
                    'name' => 'DJ Malo',
                    'username' => 'djmalo',
                    'first_name' => 'Malo',
                    'last_name' => 'Trix',
                    'display_name' => 'DJ Malo',
                    'country' => 'Uganda',
                    'city' => 'Jinja',
                    'is_verified' => true,
                    'followers_count' => 61000,
                    'instagram_url' => 'https://instagram.com/djmalo',
                ],
                'store' => [
                    'slug' => 'dj-malo-club-promotions',
                    'name' => 'DJ Malo Club Promotions',
                    'description' => 'Club and campus DJ placement for tracks that need nightlife energy and repeat spins.',
                    'phone' => '256700333444',
                    'city' => 'Jinja',
                    'country' => 'Uganda',
                    'is_verified' => true,
                    'banner' => 'store-media/promotions/dj-malo-banner.svg',
                    'brand_story' => 'Bringing records into clubs, lounges, and campus nights with proof-driven placement.',
                    'location' => 'Jinja, Uganda',
                    'audience_summary' => 'Nightlife and campus crowd with strong response to amapiano, afrobeats, and club edits.',
                    'response_time_hours' => 12,
                    'proof_points' => ['Resident at two nightlife venues', 'Weekly campus set rotation', 'Proof links after every campaign'],
                    'campaign_highlights' => ['Weekend launch spins for club-ready singles', 'Campus freshers playlist support'],
                    'portfolio_items' => [
                        [
                            'title' => 'Club premiere weekend',
                            'summary' => 'Weekend nightlife placement with MC mention and recap clips from a packed student night.',
                            'outcome' => '2 live spins, 1 host mention, 3 recap clips delivered',
                            'platform' => 'club',
                            'asset_url' => 'store-media/promotions/dj-malo-club-spin.svg',
                        ],
                    ],
                ],
                'services' => [
                    [
                        'slug' => 'dj-malo-club-spin-pack',
                        'title' => 'Club Spin Pack',
                        'short_description' => 'Weekend club placement with recap footage and crowd proof.',
                        'description' => 'A performance-centered package for artists who need their record played in a live nightlife setting with proof clips and event flyer mention.',
                        'type' => 'dj_spin',
                        'platform' => 'club',
                        'price_credits' => 5400,
                        'price_ugx' => 210000,
                        'accepts_credits' => true,
                        'accepts_ugx' => true,
                        'accepts_hybrid' => true,
                        'estimated_reach' => 1800,
                        'audience_niches' => ['nightlife', 'amapiano', 'afrobeats'],
                        'audience_regions' => ['Jinja', 'Kampala'],
                        'content_formats' => ['live_spin', 'mc_mention', 'flyer_feature'],
                        'delivery_days_min' => 2,
                        'delivery_days_max' => 4,
                        'deliverables' => ['2 spins in one event set', '1 MC mention', '2 recap clips'],
                        'requirements' => ['action' => 'Provide clean audio master and artist pronunciation', 'duration_hours' => 24, 'hashtags' => ['#ClubDrop']],
                        'terms' => 'Best suited for upbeat club records. Event placement depends on fit with the night.',
                        'featured_image' => 'store-media/promotions/dj-malo-club-spin.svg',
                        'status' => Product::STATUS_ACTIVE,
                        'is_featured' => true,
                        'published_days_ago' => 10,
                        'platform_capabilities' => ['club_spin', 'host_mention', 'crowd_recap'],
                    ],
                ],
            ],
            [
                'user' => [
                    'email' => 'teso.fm@tesotunes-test.com',
                    'name' => 'Teso FM',
                    'username' => 'tesofm',
                    'first_name' => 'Teso',
                    'last_name' => 'FM',
                    'display_name' => 'Teso FM',
                    'country' => 'Uganda',
                    'city' => 'Soroti',
                    'is_verified' => true,
                    'followers_count' => 42000,
                    'facebook_url' => 'https://facebook.com/tesofm',
                ],
                'store' => [
                    'slug' => 'teso-fm-radio-promotions',
                    'name' => 'Teso FM Radio Promotions',
                    'description' => 'Radio and presenter-driven promotion for artists targeting Eastern Uganda listeners.',
                    'phone' => '256700555666',
                    'city' => 'Soroti',
                    'country' => 'Uganda',
                    'is_verified' => true,
                    'banner' => 'store-media/promotions/teso-fm-banner.svg',
                    'brand_story' => 'Regional radio support with presenter mentions, spin logs, and audience trust.',
                    'location' => 'Soroti, Uganda',
                    'audience_summary' => 'Strong regional reach across Teso sub-region, commuters, and family listeners.',
                    'response_time_hours' => 18,
                    'proof_points' => ['Daily drive-time listeners', 'Presenter-led artist mentions', 'Spin-log proof on request'],
                    'campaign_highlights' => ['Regional support for vernacular and afro-fusion releases', 'Drive-time spotlight slots'],
                    'portfolio_items' => [
                        [
                            'title' => 'Drive-time radio spotlight',
                            'summary' => 'Presenter-backed radio mention and spin-log proof for an Eastern Uganda release push.',
                            'outcome' => '3 drive-time spins across one campaign window',
                            'platform' => 'radio',
                            'asset_url' => 'store-media/promotions/teso-fm-drive-time.svg',
                        ],
                    ],
                    'website_url' => 'https://tesofm.test',
                ],
                'services' => [
                    [
                        'slug' => 'teso-fm-drive-time-spin',
                        'title' => 'Drive-Time Radio Spin',
                        'short_description' => 'Prime-time radio support for artists targeting Eastern Uganda.',
                        'description' => 'A targeted radio placement package for artists who need a known station spin, presenter context, and proof that the agreed slot was delivered.',
                        'type' => 'radio_spin',
                        'platform' => 'radio',
                        'price_credits' => 7600,
                        'price_ugx' => 295000,
                        'accepts_credits' => false,
                        'accepts_ugx' => true,
                        'accepts_hybrid' => false,
                        'estimated_reach' => 45000,
                        'audience_niches' => ['regional radio', 'vernacular pop', 'afro-fusion'],
                        'audience_regions' => ['Teso', 'Soroti', 'Eastern Uganda'],
                        'content_formats' => ['radio_spin', 'presenter_mention', 'spin_log'],
                        'delivery_days_min' => 2,
                        'delivery_days_max' => 7,
                        'deliverables' => ['3 spins', '1 presenter mention', '1 spin log'],
                        'requirements' => ['action' => 'Send clean audio, radio edit, and artist intro', 'duration_hours' => 72, 'hashtags' => []],
                        'terms' => 'Language fit matters for presenter mentions. Prime-time requests depend on final station schedule approval.',
                        'featured_image' => 'store-media/promotions/teso-fm-drive-time.svg',
                        'status' => Product::STATUS_ACTIVE,
                        'is_featured' => true,
                        'published_days_ago' => 21,
                        'platform_capabilities' => ['spin_log', 'presenter_mention', 'regional_reach'],
                    ],
                    [
                        'slug' => 'teso-fm-weekend-new-music-slot',
                        'title' => 'Weekend New Music Slot',
                        'short_description' => 'Weekend test slot for artists validating new records with radio listeners.',
                        'description' => 'An affordable radio test package for new songs that need listener validation before a larger push.',
                        'type' => 'radio_preview',
                        'platform' => 'radio',
                        'price_credits' => 2800,
                        'price_ugx' => 110000,
                        'accepts_credits' => false,
                        'accepts_ugx' => true,
                        'accepts_hybrid' => false,
                        'estimated_reach' => 16000,
                        'audience_niches' => ['regional radio', 'new releases'],
                        'audience_regions' => ['Teso', 'Eastern Uganda'],
                        'content_formats' => ['radio_spin', 'listener_tease'],
                        'delivery_days_min' => 3,
                        'delivery_days_max' => 8,
                        'deliverables' => ['1 weekend slot', '1 recap note'],
                        'requirements' => ['action' => 'Share song file and release context', 'duration_hours' => 72, 'hashtags' => []],
                        'terms' => 'Preview slot only. Not guaranteed in drive-time rotation.',
                        'featured_image' => 'store-media/promotions/teso-fm-weekend-slot.svg',
                        'status' => Product::STATUS_DRAFT,
                        'is_featured' => false,
                        'published_days_ago' => 0,
                    ],
                ],
            ],
            [
                'user' => [
                    'email' => 'campus.buzz@tesotunes-test.com',
                    'name' => 'Campus Buzz UG',
                    'username' => 'campusbuzzug',
                    'first_name' => 'Campus',
                    'last_name' => 'Buzz',
                    'display_name' => 'Campus Buzz UG',
                    'country' => 'Uganda',
                    'city' => 'Mukono',
                    'is_verified' => false,
                    'followers_count' => 97000,
                    'instagram_url' => 'https://instagram.com/campusbuzzug',
                    'tiktok_url' => 'https://tiktok.com/@campusbuzzug',
                ],
                'store' => [
                    'slug' => 'campus-buzz-promotions',
                    'name' => 'Campus Buzz Promotions',
                    'description' => 'Youth culture placements for artists who need campus discovery and creator traction.',
                    'phone' => '256700777888',
                    'city' => 'Mukono',
                    'country' => 'Uganda',
                    'is_verified' => false,
                    'banner' => 'store-media/promotions/campus-buzz-banner.svg',
                    'brand_story' => 'We help records spread through campus creators, meme pages, and youth communities.',
                    'location' => 'Mukono, Uganda',
                    'audience_summary' => 'University students and early adopters across central Uganda.',
                    'response_time_hours' => 8,
                    'proof_points' => ['Cross-campus creator network', 'Strong meme page repost rates', 'Early-adopter music audience'],
                    'campaign_highlights' => ['Campus challenge pilots for breakout singles'],
                    'portfolio_items' => [
                        [
                            'title' => 'Campus challenge starter pack',
                            'summary' => 'Student creator seeding with meme-page repost support around a breakout single.',
                            'outcome' => '14 reposts and strong first-week student engagement',
                            'platform' => 'tiktok',
                            'asset_url' => 'store-media/promotions/campus-buzz-student-pack.svg',
                        ],
                    ],
                ],
                'services' => [
                    [
                        'slug' => 'campus-buzz-student-hype-pack',
                        'title' => 'Student Hype Pack',
                        'short_description' => 'Campus-oriented short-form push for energetic records.',
                        'description' => 'A youth-focused package built around student creators, meme-friendly framing, and relatable campus momentum for new songs.',
                        'type' => 'creator_network_push',
                        'platform' => 'tiktok',
                        'price_credits' => 4300,
                        'price_ugx' => 165000,
                        'accepts_credits' => true,
                        'accepts_ugx' => true,
                        'accepts_hybrid' => true,
                        'estimated_reach' => 38000,
                        'audience_niches' => ['campus culture', 'student creators', 'viral hooks'],
                        'audience_regions' => ['Kampala', 'Mukono', 'Central Uganda'],
                        'content_formats' => ['short_video', 'duet', 'meme_clip'],
                        'delivery_days_min' => 2,
                        'delivery_days_max' => 5,
                        'deliverables' => ['1 creator post', '2 reposts', 'campaign recap'],
                        'requirements' => ['action' => 'Share hook line and mood direction', 'duration_hours' => 48, 'hashtags' => ['#CampusBuzz', '#SongOfTheWeek']],
                        'terms' => 'Best for clean records that fit student culture and meme-ready hooks.',
                        'featured_image' => 'store-media/promotions/campus-buzz-student-pack.svg',
                        'status' => Product::STATUS_ACTIVE,
                        'is_featured' => false,
                        'published_days_ago' => 9,
                    ],
                ],
            ],
        ];
    }
}
