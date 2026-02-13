<?php

namespace App\Modules\Store\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Store\Models\ProductCategory;
use Illuminate\Support\Str;

class StoreCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Merchandise',
                'slug' => 'merchandise',
                'description' => 'Artist merchandise including t-shirts, hoodies, and accessories',
                'icon' => 'checkroom',
                'sort_order' => 1,
                'children' => [
                    ['name' => 'T-Shirts', 'slug' => 't-shirts', 'icon' => 'checkroom'],
                    ['name' => 'Hoodies', 'slug' => 'hoodies', 'icon' => 'checkroom'],
                    ['name' => 'Hats & Caps', 'slug' => 'hats-caps', 'icon' => 'checkroom'],
                    ['name' => 'Accessories', 'slug' => 'accessories', 'icon' => 'watch'],
                ]
            ],
            [
                'name' => 'Digital Products',
                'slug' => 'digital-products',
                'description' => 'Digital downloads including beats, samples, and instrumentals',
                'icon' => 'audiotrack',
                'sort_order' => 2,
                'children' => [
                    ['name' => 'Beats', 'slug' => 'beats', 'icon' => 'music_note'],
                    ['name' => 'Samples', 'slug' => 'samples', 'icon' => 'library_music'],
                    ['name' => 'Instrumentals', 'slug' => 'instrumentals', 'icon' => 'piano'],
                    ['name' => 'Sound Packs', 'slug' => 'sound-packs', 'icon' => 'queue_music'],
                ]
            ],
            [
                'name' => 'Services',
                'slug' => 'services',
                'description' => 'Professional music services',
                'icon' => 'work',
                'sort_order' => 3,
                'children' => [
                    ['name' => 'Music Production', 'slug' => 'music-production', 'icon' => 'graphic_eq'],
                    ['name' => 'Mixing & Mastering', 'slug' => 'mixing-mastering', 'icon' => 'tune'],
                    ['name' => 'Songwriting', 'slug' => 'songwriting', 'icon' => 'edit_note'],
                    ['name' => 'Consultation', 'slug' => 'consultation', 'icon' => 'psychology'],
                ]
            ],
            [
                'name' => 'Experiences',
                'slug' => 'experiences',
                'description' => 'Unique experiences with artists',
                'icon' => 'stars',
                'sort_order' => 4,
                'children' => [
                    ['name' => 'Meet & Greet', 'slug' => 'meet-greet', 'icon' => 'handshake'],
                    ['name' => 'Studio Visit', 'slug' => 'studio-visit', 'icon' => 'home_work'],
                    ['name' => 'Video Call', 'slug' => 'video-call', 'icon' => 'video_call'],
                    ['name' => 'Dinner Date', 'slug' => 'dinner-date', 'icon' => 'restaurant'],
                ]
            ],
            [
                'name' => 'Promotions',
                'slug' => 'promotions',
                'description' => 'Music promotion services',
                'icon' => 'campaign',
                'sort_order' => 5,
                'children' => [
                    ['name' => 'Radio Play', 'slug' => 'radio-play', 'icon' => 'radio'],
                    ['name' => 'DJ Shoutout', 'slug' => 'dj-shoutout', 'icon' => 'mic'],
                    ['name' => 'Social Media Post', 'slug' => 'social-media-post', 'icon' => 'share'],
                    ['name' => 'Playlist Placement', 'slug' => 'playlist-placement', 'icon' => 'playlist_add'],
                ]
            ],
            [
                'name' => 'Event Tickets',
                'slug' => 'event-tickets',
                'description' => 'Concert and event tickets',
                'icon' => 'confirmation_number',
                'sort_order' => 6,
                'children' => [
                    ['name' => 'Concerts', 'slug' => 'concerts', 'icon' => 'music_note'],
                    ['name' => 'Meet & Greet', 'slug' => 'meet-greet-tickets', 'icon' => 'handshake'],
                    ['name' => 'VIP Access', 'slug' => 'vip-access', 'icon' => 'workspace_premium'],
                ]
            ],
        ];

        foreach ($categories as $categoryData) {
            $children = $categoryData['children'] ?? [];
            unset($categoryData['children']);

            $category = ProductCategory::create($categoryData);

            foreach ($children as $childData) {
                ProductCategory::create([
                    'name' => $childData['name'],
                    'slug' => $childData['slug'],
                    'icon' => $childData['icon'],
                    'parent_id' => $category->id,
                    'sort_order' => 0,
                    'is_active' => true,
                ]);
            }
        }

        $this->command->info('✓ Created ' . ProductCategory::count() . ' product categories');
    }
}
