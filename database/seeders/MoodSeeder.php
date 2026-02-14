<?php

namespace Database\Seeders;

use App\Models\Mood;
use Illuminate\Database\Seeder;

class MoodSeeder extends Seeder
{
    public function run(): void
    {
        $moods = [
            ['name' => 'Happy',       'slug' => 'happy',       'description' => 'Upbeat and joyful music',        'color' => '#FBBF24', 'sort_order' => 1],
            ['name' => 'Sad',          'slug' => 'sad',         'description' => 'Melancholic and emotional music', 'color' => '#3B82F6', 'sort_order' => 2],
            ['name' => 'Energetic',    'slug' => 'energetic',   'description' => 'High-energy and pumping beats',   'color' => '#EF4444', 'sort_order' => 3],
            ['name' => 'Chill',        'slug' => 'chill',       'description' => 'Relaxed and laid-back vibes',     'color' => '#06B6D4', 'sort_order' => 4],
            ['name' => 'Romantic',     'slug' => 'romantic',    'description' => 'Love and romance themed music',   'color' => '#EC4899', 'sort_order' => 5],
            ['name' => 'Party',        'slug' => 'party',       'description' => 'Dance and party anthems',         'color' => '#F97316', 'sort_order' => 6],
            ['name' => 'Motivational', 'slug' => 'motivational','description' => 'Inspiring and uplifting music',   'color' => '#10B981', 'sort_order' => 7],
            ['name' => 'Worship',      'slug' => 'worship',     'description' => 'Spiritual and devotional music',  'color' => '#8B5CF6', 'sort_order' => 8],
            ['name' => 'Nostalgic',    'slug' => 'nostalgic',   'description' => 'Throwback and classic feel',      'color' => '#A855F7', 'sort_order' => 9],
            ['name' => 'Focus',        'slug' => 'focus',       'description' => 'Study and concentration music',   'color' => '#14B8A6', 'sort_order' => 10],
        ];

        foreach ($moods as $mood) {
            Mood::updateOrCreate(
                ['slug' => $mood['slug']],
                array_merge($mood, ['is_active' => true])
            );
        }
    }
}
