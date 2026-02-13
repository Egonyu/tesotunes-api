<?php

namespace Database\Factories;

use App\Models\PodcastEpisode;
use App\Models\Podcast;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PodcastEpisodeFactory extends Factory
{
    protected $model = PodcastEpisode::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(4);
        
        return [
            'podcast_id' => Podcast::factory(),
            'sponsor_id' => null,
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => $this->faker->paragraph(),
            'audio_file' => 'episodes/' . $this->faker->uuid() . '.mp3',
            'artwork' => $this->faker->optional()->imageUrl(640, 640),
            'duration_seconds' => $this->faker->numberBetween(300, 7200),
            'episode_number' => $this->faker->numberBetween(1, 100),
            'season_number' => $this->faker->optional()->numberBetween(1, 10),
            'type' => $this->faker->randomElement(['full', 'trailer', 'bonus']),
            'is_explicit' => $this->faker->boolean(20),
            'status' => $this->faker->randomElement(['draft', 'published', 'scheduled']),
            'is_premium' => $this->faker->boolean(30),
            'has_preview' => $this->faker->boolean(40),
            'preview_duration_seconds' => $this->faker->optional()->numberBetween(60, 300),
            'published_date' => $this->faker->optional()->date(),
            'published_at' => $this->faker->optional()->dateTimeBetween('-1 year', 'now'),
            'listen_count' => $this->faker->numberBetween(0, 10000),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'published_at' => now(),
            'published_date' => now()->toDateString(),
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_premium' => true,
            'has_preview' => true,
            'preview_duration_seconds' => 120,
        ]);
    }
}
