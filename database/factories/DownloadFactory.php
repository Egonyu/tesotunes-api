<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Download>
 */
class DownloadFactory extends Factory
{
    /**
     * Define the model's default state.
     * FIXED: Using polymorphic morphs as per base migration schema
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Downloads use polymorphic relationship (downloadable_type, downloadable_id)
        return [
            'user_id' => \App\Models\User::factory(),
            'downloadable_type' => 'App\\Models\\Song',
            'downloadable_id' => \App\Models\Song::factory(),
            'quality' => fake()->randomElement(['128', '320', 'original']),
            'source' => fake()->randomElement(['web', 'mobile', 'api']),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            // Columns from comprehensive_schema_sync
            'uuid' => fake()->uuid(),
            'file_size' => fake()->numberBetween(1000000, 10000000),
            'credits_used' => fake()->randomElement([0, 1, 2, 5]),
        ];
    }

    /**
     * Create a download for a specific song.
     */
    public function forSong(\App\Models\Song $song): static
    {
        return $this->state(fn (array $attributes) => [
            'downloadable_type' => 'App\\Models\\Song',
            'downloadable_id' => $song->id,
        ]);
    }

    /**
     * Create a download for a specific album.
     */
    public function forAlbum(\App\Models\Album $album): static
    {
        return $this->state(fn (array $attributes) => [
            'downloadable_type' => 'App\\Models\\Album',
            'downloadable_id' => $album->id,
        ]);
    }
}
