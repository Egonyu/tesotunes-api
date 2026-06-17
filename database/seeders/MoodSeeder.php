<?php

namespace Database\Seeders;

use App\Models\Mood;
use App\Models\Song;
use Illuminate\Database\Seeder;

class MoodSeeder extends Seeder
{
    public function run(): void
    {
        $moods = [
            ['name' => 'Happy',        'slug' => 'happy',        'description' => 'Upbeat and joyful music',         'color' => '#FBBF24', 'display_order' => 1],
            ['name' => 'Sad',          'slug' => 'sad',          'description' => 'Melancholic and emotional music',  'color' => '#3B82F6', 'display_order' => 2],
            ['name' => 'Energetic',    'slug' => 'energetic',    'description' => 'High-energy and pumping beats',    'color' => '#EF4444', 'display_order' => 3],
            ['name' => 'Chill',        'slug' => 'chill',        'description' => 'Relaxed and laid-back vibes',      'color' => '#06B6D4', 'display_order' => 4],
            ['name' => 'Romantic',     'slug' => 'romantic',     'description' => 'Love and romance themed music',    'color' => '#EC4899', 'display_order' => 5],
            ['name' => 'Party',        'slug' => 'party',        'description' => 'Dance and party anthems',          'color' => '#F97316', 'display_order' => 6],
            ['name' => 'Motivational', 'slug' => 'motivational', 'description' => 'Inspiring and uplifting music',    'color' => '#10B981', 'display_order' => 7],
            ['name' => 'Worship',      'slug' => 'worship',      'description' => 'Spiritual and devotional music',   'color' => '#8B5CF6', 'display_order' => 8],
            ['name' => 'Nostalgic',    'slug' => 'nostalgic',    'description' => 'Throwback and classic feel',       'color' => '#A855F7', 'display_order' => 9],
            ['name' => 'Focus',        'slug' => 'focus',        'description' => 'Study and concentration music',    'color' => '#14B8A6', 'display_order' => 10],
        ];

        foreach ($moods as $mood) {
            Mood::updateOrCreate(
                ['slug' => $mood['slug']],
                array_merge($mood, ['is_active' => true])
            );
        }

        $this->attachSongs();
    }

    /**
     * Populate each mood with a *varied* slice of published songs so the mixes
     * feel distinct rather than all showing the same set. Uses sync() so the mix
     * is re-established (not accumulated) on each run. Heuristic seed data — not
     * a real mood classifier.
     */
    private function attachSongs(): void
    {
        $songIds = Song::query()->where('status', 'published')->pluck('id');

        if ($songIds->isEmpty()) {
            return;
        }

        $total = $songIds->count();
        $ceiling = min(28, $total);
        $floor = min(8, $ceiling);

        Mood::query()->get()->each(function (Mood $mood) use ($songIds, $floor, $ceiling) {
            // A random count in a fixed range (not a % of the catalogue, which
            // would saturate on large catalogues) plus a shuffled membership, so
            // each mood lands on a visibly different size and song set.
            $count = random_int($floor, $ceiling);
            $picked = $songIds->shuffle()->take($count)->all();
            $mood->songs()->sync($picked);
        });
    }
}
