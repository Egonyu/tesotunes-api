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
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $playDuration = fake()->numberBetween(30, 300);
        $durationPlayed = fake()->numberBetween(10, $playDuration);
        $completionPercentage = ($durationPlayed / $playDuration) * 100;

        return [
            'session_id' => fake()->uuid(),
            'ip_address' => fake()->ipv4(),
            'country_code' => 'UG',
            'city' => fake()->randomElement(['Kampala', 'Entebbe', 'Jinja', 'Mbarara']),
            'device_type' => fake()->randomElement(['mobile', 'tablet', 'desktop', 'tv', 'other']),
            'platform' => fake()->randomElement(['web', 'ios', 'android', 'api', 'other']),
            'user_agent' => fake()->userAgent(),
            'played_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'play_duration_seconds' => $playDuration,
            'duration_played' => $durationPlayed,
            'position_seconds' => fake()->numberBetween(0, $durationPlayed),
            'completion_percentage' => round($completionPercentage, 2),
            'was_completed' => $completionPercentage >= 80,
            'was_skipped' => fake()->boolean(20),
            'audio_quality' => fake()->randomElement(['128kbps', '256kbps', '320kbps', 'original']),
            'came_from' => fake()->randomElement(['discover', 'search', 'playlist', 'album', 'artist_page', 'recommendation', 'share_link', 'external', 'other']),
            'referrer_url' => fake()->optional()->url(),
            'counts_for_revenue' => fake()->boolean(90),
        ];
    }
}
