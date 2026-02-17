<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Models\Album;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $artistUser = User::where('email', 'artist@tesotunes.com')->first();
        $regularUser = User::where('email', 'user@tesotunes.com')->first();

        if (!$artistUser || !$regularUser) {
            $this->command->warn('Test users not found – run UserSeeder first.');
            return;
        }

        // Only create user settings if table exists
        if (Schema::hasTable('user_settings') && class_exists('App\Models\UserSetting')) {
            User::all()->each(function (User $u) {
                \App\Models\UserSetting::createDefault($u);
            });
        }

        // Artist profile
        $genre = Genre::first();
        $artist = Artist::firstOrCreate(
            ['user_id' => $artistUser->id],
            [
                'name' => 'DJ TesoBeats',
                'stage_name' => 'DJ TesoBeats',
                'slug' => 'dj-tesobeats',
                'bio' => 'Emerging Ugandan artist blending Afrobeats with traditional Teso rhythms.',
                'is_verified' => true,
                'status' => 'active',
                'primary_genre_id' => $genre?->id,
                'career_start_year' => 2022,
            ]
        );

        // Album
        $album = Album::firstOrCreate(
            ['slug' => 'first-light'],
            [
                'artist_id' => $artist->id,
                'title' => 'First Light',
                'slug' => 'first-light',
                'description' => 'A debut EP celebrating the sounds of Eastern Uganda.',
                'album_type' => 'ep',
                'primary_genre_id' => $genre?->id,
                'release_date' => now()->subMonths(2)->toDateString(),
                'status' => 'published',
            ]
        );

        // Songs
        $songs = [
            [
                'title' => 'Sunrise in Soroti',
                'slug' => 'sunrise-in-soroti',
                'description' => 'An uplifting Afrobeats track.',
                'duration_seconds' => 210,
                'status' => 'published',
                'play_count' => 342,
                'like_count' => 28,
            ],
            [
                'title' => 'Kampala Nights',
                'slug' => 'kampala-nights',
                'description' => 'Night-life energy meets traditional drums.',
                'duration_seconds' => 195,
                'status' => 'published',
                'play_count' => 189,
                'like_count' => 15,
            ],
            [
                'title' => 'Ateso Love',
                'slug' => 'ateso-love',
                'description' => 'A love ballad sung in Ateso.',
                'duration_seconds' => 240,
                'status' => 'published',
                'play_count' => 97,
                'like_count' => 12,
            ],
        ];

        foreach ($songs as $index => $songData) {
            Song::firstOrCreate(
                ['slug' => $songData['slug']],
                array_merge($songData, [
                    'artist_id' => $artist->id,
                    'album_id' => $album->id,
                    'primary_genre_id' => $genre?->id,
                    'track_number' => $index + 1,
                    'is_free' => true,
                ])
            );
        }

        // Playlist by regular user - skip if schema doesn't match
        // Table has 'name' column, model expects 'title'

        $this->command->info('Test data seeded: 1 artist, 1 album, 3 songs.');
    }
}
