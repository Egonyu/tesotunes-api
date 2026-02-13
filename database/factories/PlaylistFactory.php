<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Playlist>
 */
class PlaylistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 10000),
            'description' => fake()->paragraph(),
            'artwork' => 'playlists/' . fake()->uuid() . '.jpg',
            'visibility' => fake()->randomElement(['public', 'private', 'unlisted']),
            'is_collaborative' => fake()->boolean(20),
            'is_featured' => fake()->boolean(5),
            'song_count' => fake()->numberBetween(0, 50),
            'total_duration_seconds' => fake()->numberBetween(600, 7200), // 10 min to 2 hours
            'play_count' => fake()->numberBetween(0, 10000),
            'follower_count' => fake()->numberBetween(0, 1000),
        ];
    }
}
