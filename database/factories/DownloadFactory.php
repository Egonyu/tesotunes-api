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
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'song_id' => \App\Models\Song::factory(),
            'ip_address' => fake()->ipv4(),
            'downloaded_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'quality' => fake()->randomElement(['128kbps', '320kbps', 'original']),
            'file_size_bytes' => fake()->numberBetween(1000000, 10000000),
            'device_type' => fake()->randomElement(['mobile', 'tablet', 'desktop']),
            'is_active' => true,
        ];
    }
}
