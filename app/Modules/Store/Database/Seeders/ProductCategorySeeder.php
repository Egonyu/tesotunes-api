<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!config('store.enabled', false)) {
            return;
        }

        $categories = [
            // Merchandise Categories
            [
                'name' => 'Merchandise',
                'slug' => 'merchandise',
                'description' => 'Physical products like T-shirts, hoodies, and accessories',
                'icon' => 'shopping-bag',
                'sort_order' => 1,
                'children' => [
                    ['name' => 'T-Shirts', 'slug' => 't-shirts', 'icon' => 'shirt'],
                    ['name' => 'Hoodies', 'slug' => 'hoodies', 'icon' => 'hoodie'],
                    ['name' => 'Caps & Hats', 'slug' => 'caps-hats', 'icon' => 'hat'],
                    ['name' => 'Accessories', 'slug' => 'accessories', 'icon' => 'watch'],
                    ['name' => 'Posters', 'slug' => 'posters', 'icon' => 'image'],
                    ['name' => 'Stickers', 'slug' => 'stickers', 'icon' => 'sticker'],
                ],
            ],
            
            // Experience Categories
            [
                'name' => 'Experiences',
                'slug' => 'experiences',
                'description' => 'Exclusive experiences with artists',
                'icon' => 'calendar-heart',
                'sort_order' => 2,
                'children' => [
                    ['name' => 'Meet & Greet', 'slug' => 'meet-greet', 'icon' => 'handshake'],
                    ['name' => 'Dinner Date', 'slug' => 'dinner-date', 'icon' => 'utensils'],
                    ['name' => 'Studio Visit', 'slug' => 'studio-visit', 'icon' => 'microphone'],
                    ['name' => 'Video Call', 'slug' => 'video-call', 'icon' => 'video'],
                    ['name' => 'Private Performance', 'slug' => 'private-performance', 'icon' => 'music'],
                ],
            ],
            
            // Promotional Services
            [
                'name' => 'Promotional Services',
                'slug' => 'promotional-services',
                'description' => 'Radio mentions, DJ shoutouts, and playlist placements',
                'icon' => 'megaphone',
                'sort_order' => 3,
                'children' => [
                    ['name' => 'Radio Mentions', 'slug' => 'radio-mentions', 'icon' => 'radio'],
                    ['name' => 'DJ Shoutouts', 'slug' => 'dj-shoutouts', 'icon' => 'disc'],
                    ['name' => 'Playlist Placement', 'slug' => 'playlist-placement', 'icon' => 'list-music'],
                    ['name' => 'Social Media Promotion', 'slug' => 'social-media-promo', 'icon' => 'share-2'],
                    ['name' => 'Blog Features', 'slug' => 'blog-features', 'icon' => 'newspaper'],
                ],
            ],
            
            // Digital Products
            [
                'name' => 'Digital Products',
                'slug' => 'digital-products',
                'description' => 'Beats, samples, and digital downloads',
                'icon' => 'download',
                'sort_order' => 4,
                'children' => [
                    ['name' => 'Beats & Instrumentals', 'slug' => 'beats', 'icon' => 'waveform'],
                    ['name' => 'Sample Packs', 'slug' => 'samples', 'icon' => 'layers'],
                    ['name' => 'Stems', 'slug' => 'stems', 'icon' => 'git-branch'],
                    ['name' => 'Exclusive Tracks', 'slug' => 'exclusive-tracks', 'icon' => 'music'],
                    ['name' => 'Tutorials', 'slug' => 'tutorials', 'icon' => 'book-open'],
                ],
            ],
            
            // Event Tickets
            [
                'name' => 'Event Tickets',
                'slug' => 'event-tickets',
                'description' => 'Concert tickets and event passes',
                'icon' => 'ticket',
                'sort_order' => 5,
                'children' => [
                    ['name' => 'Concerts', 'slug' => 'concerts', 'icon' => 'music-2'],
                    ['name' => 'Album Launches', 'slug' => 'album-launches', 'icon' => 'disc'],
                    ['name' => 'Listening Parties', 'slug' => 'listening-parties', 'icon' => 'headphones'],
                    ['name' => 'VIP Passes', 'slug' => 'vip-passes', 'icon' => 'award'],
                ],
            ],
            
            // Services
            [
                'name' => 'Services',
                'slug' => 'services',
                'description' => 'Professional services for music production',
                'icon' => 'briefcase',
                'sort_order' => 6,
                'children' => [
                    ['name' => 'Production', 'slug' => 'production', 'icon' => 'sliders'],
                    ['name' => 'Mixing & Mastering', 'slug' => 'mixing-mastering', 'icon' => 'bar-chart'],
                    ['name' => 'Graphic Design', 'slug' => 'graphic-design', 'icon' => 'palette'],
                    ['name' => 'Photography', 'slug' => 'photography', 'icon' => 'camera'],
                    ['name' => 'Videography', 'slug' => 'videography', 'icon' => 'video'],
                ],
            ],
        ];

        foreach ($categories as $category) {
            $children = $category['children'] ?? [];
            unset($category['children']);
            
            $category['created_at'] = now();
            $category['updated_at'] = now();
            
            $parentId = DB::table('product_categories')->insertGetId($category);
            
            // Insert children
            foreach ($children as $child) {
                $child['parent_id'] = $parentId;
                $child['sort_order'] = 0;
                $child['created_at'] = now();
                $child['updated_at'] = now();
                
                DB::table('product_categories')->insert($child);
            }
        }

        $this->command->info('✓ Product categories seeded successfully');
    }
}
