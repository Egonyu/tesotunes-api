<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;

class GenreSeeder extends Seeder
{
    public function run(): void
    {
        $genres = [
            ['name' => 'Afrobeat',       'slug' => 'afrobeat',       'description' => 'Modern African rhythm fusing Yoruba music, jazz, and funk', 'color' => '#E11D48', 'sort_order' => 1],
            ['name' => 'Bongo Flava',    'slug' => 'bongo-flava',    'description' => 'Tanzanian popular music blending hip-hop, R&B, and taarab', 'color' => '#F97316', 'sort_order' => 2],
            ['name' => 'Kadongo Kamu',   'slug' => 'kadongo-kamu',   'description' => 'Ugandan acoustic folk music tradition', 'color' => '#10B981', 'sort_order' => 3],
            ['name' => 'Gospel',         'slug' => 'gospel',         'description' => 'African gospel and worship music', 'color' => '#6366F1', 'sort_order' => 4],
            ['name' => 'Hip Hop',        'slug' => 'hip-hop',        'description' => 'East African hip-hop and rap', 'color' => '#8B5CF6', 'sort_order' => 5],
            ['name' => 'Dancehall',      'slug' => 'dancehall',      'description' => 'Caribbean-influenced dancehall and reggae', 'color' => '#EC4899', 'sort_order' => 6],
            ['name' => 'R&B',           'slug' => 'rnb',            'description' => 'Rhythm and blues with African flavor', 'color' => '#06B6D4', 'sort_order' => 7],
            ['name' => 'Traditional',    'slug' => 'traditional',    'description' => 'Traditional East African folk music', 'color' => '#84CC16', 'sort_order' => 8],
            ['name' => 'Zouk',           'slug' => 'zouk',           'description' => 'Slow romantic dance music', 'color' => '#F43F5E', 'sort_order' => 9],
            ['name' => 'Reggae',         'slug' => 'reggae',         'description' => 'Jamaican-inspired reggae music', 'color' => '#22C55E', 'sort_order' => 10],
            ['name' => 'Afro Pop',       'slug' => 'afro-pop',       'description' => 'Contemporary African pop music', 'color' => '#A855F7', 'sort_order' => 11],
            ['name' => 'Kidandali',      'slug' => 'kidandali',      'description' => 'Ugandan dance music genre', 'color' => '#EAB308', 'sort_order' => 12],
            ['name' => 'Rumba',          'slug' => 'rumba',          'description' => 'Congolese rumba and soukous', 'color' => '#14B8A6', 'sort_order' => 13],
            ['name' => 'Amapiano',       'slug' => 'amapiano',       'description' => 'South African deep house subgenre', 'color' => '#3B82F6', 'sort_order' => 14],
            ['name' => 'Band Music',     'slug' => 'band-music',     'description' => 'Live band performances and recordings', 'color' => '#D946EF', 'sort_order' => 15],
        ];

        foreach ($genres as $genre) {
            Genre::updateOrCreate(
                ['slug' => $genre['slug']],
                array_merge($genre, ['is_active' => true])
            );
        }
    }
}
