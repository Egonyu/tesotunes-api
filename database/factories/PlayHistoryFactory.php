<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlayHistory>
 */
class PlayHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     * FIXED: Aligned with actual migration schema (base migration + comprehensive sync)
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $durationPlayed = fake()->numberBetween(30, 300);

        return [
            'user_id' => \App\Models\User::factory(),
            'song_id' => \App\Models\Song::factory(),
            'duration_played' => $durationPlayed,
            'completed' => $durationPlayed >= 240, // Consider completed if > 4 mins
            'source' => fake()->randomElement(['discover', 'search', 'playlist', 'album', 'artist_page', 'recommendation', 'share_link']),
            'device_type' => fake()->randomElement(['mobile', 'tablet', 'desktop', 'tv']),
            // Additional columns from comprehensive_schema_sync migration
            'duration_listened' => $durationPlayed,
            'playlist_id' => null,
            'ip_address' => fake()->ipv4(),
            'country' => 'UG',
        ];
    }
}
