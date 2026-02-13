<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Album>
 */
class AlbumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->words(3, true);
        
        return [
            'user_id' => \App\Models\User::factory(),
            'artist_id' => \App\Models\Artist::factory(),
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 10000),
            'description' => fake()->paragraph(),
            'artwork' => 'albums/' . fake()->uuid() . '.jpg',
            'album_type' => fake()->randomElement(['single', 'ep', 'album', 'compilation']),
            'release_date' => fake()->date(),
            'release_year' => fake()->year(),
            'status' => fake()->randomElement(['draft', 'pending_review', 'approved', 'published', 'archived']),
            'visibility' => fake()->randomElement(['public', 'private', 'unlisted']),
            'is_explicit' => fake()->boolean(20),
            'price' => null,
            'is_free' => true,
            'currency' => 'UGX',
            'total_tracks' => fake()->numberBetween(1, 20),
            'total_duration_seconds' => fake()->numberBetween(600, 3600),
            'play_count' => fake()->numberBetween(0, 100000),
            'download_count' => fake()->numberBetween(0, 10000),
            'like_count' => fake()->numberBetween(0, 5000),
        ];
    }
}
