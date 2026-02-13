<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Song>
 */
class SongFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);
        
        return [
            'user_id' => \App\Models\User::factory(),
            'artist_id' => \App\Models\Artist::factory(),
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 10000),
            'description' => fake()->paragraph(),
            'lyrics' => fake()->paragraphs(3, true),
            'audio_file_original' => 'songs/original/' . fake()->uuid() . '.mp3',
            'audio_file_320' => 'songs/320kbps/' . fake()->uuid() . '.mp3',
            'audio_file_128' => 'songs/128kbps/' . fake()->uuid() . '.mp3',
            'audio_file_preview' => 'songs/preview/' . fake()->uuid() . '.mp3',
            'artwork' => 'artwork/' . fake()->uuid() . '.jpg',
            'duration_seconds' => fake()->numberBetween(120, 360),
            'file_size_bytes' => fake()->numberBetween(3000000, 10000000),
            'bitrate_original' => 320,
            'file_format' => 'mp3',
            'status' => fake()->randomElement(['draft', 'pending_review', 'approved', 'published']),
            'visibility' => fake()->randomElement(['public', 'private', 'unlisted']),
            'is_explicit' => fake()->boolean(20),
            'is_featured' => fake()->boolean(10),
            'is_downloadable' => true,
            'price' => null,
            'currency' => 'UGX',
            'play_count' => fake()->numberBetween(0, 100000),
            'download_count' => fake()->numberBetween(0, 10000),
            'like_count' => fake()->numberBetween(0, 5000),
            'share_count' => fake()->numberBetween(0, 1000),
            'comment_count' => fake()->numberBetween(0, 500),
        ];
    }
}
