<?php

namespace Database\Factories;

use App\Models\Distribution;
use App\Models\Song;
use App\Models\ArtistProfile;
use App\Models\DistributionPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

class DistributionFactory extends Factory
{
    protected $model = Distribution::class;

    public function definition(): array
    {
        $platformCode = $this->faker->randomElement([
            'spotify',
            'apple_music',
            'youtube_music',
            'amazon_music',
            'deezer',
            'tidal',
            'pandora',
            'soundcloud',
            'bandcamp'
        ]);

        $platformNames = [
            'spotify' => 'Spotify',
            'apple_music' => 'Apple Music',
            'youtube_music' => 'YouTube Music',
            'amazon_music' => 'Amazon Music',
            'deezer' => 'Deezer',
            'tidal' => 'Tidal',
            'pandora' => 'Pandora',
            'soundcloud' => 'SoundCloud',
            'bandcamp' => 'Bandcamp',
        ];

        $status = $this->faker->randomElement([
            'pending',
            'processing',
            'live',
            'failed',
            'rejected',
            'removed'
        ]);

        return [
            'song_id' => Song::factory(),
            'artist_id' => ArtistProfile::factory(),
            'platform_code' => $platformCode,
            'platform_name' => $platformNames[$platformCode],
            'status' => $status,
            'distribution_metadata' => [
                'release_date' => $this->faker->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
                'territories' => $this->faker->randomElements([
                    'worldwide',
                    'uganda',
                    'east_africa',
                    'africa',
                    'north_america',
                    'europe'
                ], $this->faker->numberBetween(1, 3)),
                'content_advisory' => $this->faker->randomElement(['clean', 'explicit']),
                'genre' => $this->faker->randomElement([
                    'Afrobeat',
                    'Pop',
                    'Hip Hop',
                    'R&B',
                    'Gospel',
                    'Traditional'
                ]),
                'language' => $this->faker->randomElement(['en', 'lg', 'sw']),
                'price_tier' => $this->faker->randomElement(['free', 'standard', 'premium']),
                'pre_order' => $this->faker->boolean(20),
            ],
            'platform_metadata' => $this->generatePlatformMetadata($platformCode, $status),
            'live_date' => $status === 'live' ? $this->faker->dateTimeBetween('-6 months', 'now') : null,
            'platform_url' => $status === 'live' ? $this->generatePlatformUrl($platformCode) : null,
            'platform_id' => $status === 'live' ? $this->generatePlatformId($platformCode) : null,
            'error_message' => in_array($status, ['failed', 'rejected']) ? $this->faker->sentence() : null,
            'rejection_reason' => $status === 'rejected' ? $this->faker->randomElement([
                'Audio quality does not meet standards',
                'Copyright infringement detected',
                'Missing metadata',
                'Invalid file format',
                'Explicit content not properly labeled'
            ]) : null,
            'removed_date' => $status === 'removed' ? $this->faker->dateTimeBetween('-3 months', 'now') : null,
            'removal_reason' => $status === 'removed' ? $this->faker->randomElement([
                'Artist request',
                'Copyright claim',
                'Platform policy violation',
                'Technical issues'
            ]) : null,
            'removal_requested_at' => null,
            'last_updated' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'last_synced' => $this->faker->optional(0.8)->dateTimeBetween('-1 week', 'now'),
            'retry_count' => $status === 'failed' ? $this->faker->numberBetween(1, 3) : 0,
            'total_streams' => $status === 'live' ? $this->faker->numberBetween(100, 100000) : 0,
            'total_revenue' => $status === 'live' ? $this->faker->randomFloat(2, 10, 5000) : 0,
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at' => now(),
        ];
    }

    private function generatePlatformMetadata(string $platformCode, string $status): array
    {
        $baseMetadata = [
            'submission_id' => strtoupper($platformCode . '_' . $this->faker->bothify('???###???')),
            'submission_date' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
        ];

        if ($status === 'live') {
            $baseMetadata = array_merge($baseMetadata, [
                'streams' => $this->faker->numberBetween(100, 100000),
                'revenue' => $this->faker->randomFloat(2, 10, 5000),
                'listeners' => $this->faker->numberBetween(50, 10000),
                'countries' => $this->faker->numberBetween(1, 50),
                'playlist_adds' => $this->faker->numberBetween(0, 500),
                'last_revenue_sync' => $this->faker->dateTimeBetween('-1 week', 'now')->format('Y-m-d'),
            ]);
        }

        if (in_array($status, ['failed', 'rejected'])) {
            $baseMetadata['error_code'] = $this->faker->randomElement([
                'AUDIO_QUALITY_LOW',
                'METADATA_MISSING',
                'COPYRIGHT_ISSUE',
                'FORMAT_INVALID',
                'CONTENT_POLICY_VIOLATION'
            ]);
        }

        return $baseMetadata;
    }

    private function generatePlatformUrl(string $platformCode): string
    {
        $trackId = $this->faker->bothify('??????????????');

        return match($platformCode) {
            'spotify' => "https://open.spotify.com/track/{$trackId}",
            'apple_music' => "https://music.apple.com/song/{$trackId}",
            'youtube_music' => "https://music.youtube.com/watch?v={$trackId}",
            'amazon_music' => "https://music.amazon.com/tracks/{$trackId}",
            'deezer' => "https://www.deezer.com/track/{$trackId}",
            'tidal' => "https://tidal.com/track/{$trackId}",
            'soundcloud' => "https://soundcloud.com/artist/track-{$trackId}",
            'bandcamp' => "https://artist.bandcamp.com/track/track-{$trackId}",
            default => "https://{$platformCode}.com/track/{$trackId}",
        };
    }

    private function generatePlatformId(string $platformCode): string
    {
        return match($platformCode) {
            'spotify' => 'spotify:track:' . $this->faker->bothify('??????????????????'),
            'apple_music' => $this->faker->numerify('##########'),
            'youtube_music' => $this->faker->bothify('???????????'),
            'amazon_music' => 'AMZN_' . $this->faker->bothify('??????????'),
            'deezer' => $this->faker->numerify('########'),
            'tidal' => $this->faker->numerify('########'),
            'soundcloud' => $this->faker->numerify('########'),
            'bandcamp' => $this->faker->numerify('########'),
            default => $this->faker->bothify('??????????'),
        };
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'live_date' => null,
            'platform_url' => null,
            'platform_id' => null,
            'error_message' => null,
            'rejection_reason' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'live_date' => null,
            'platform_url' => null,
            'platform_id' => null,
            'error_message' => null,
            'rejection_reason' => null,
            'platform_metadata' => array_merge($attributes['platform_metadata'] ?? [], [
                'processing_started' => now()->subHours($this->faker->numberBetween(1, 48))->toISOString(),
                'estimated_completion' => now()->addHours($this->faker->numberBetween(12, 72))->toISOString(),
            ]),
        ]);
    }

    public function live(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'live',
            'live_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'platform_url' => $this->generatePlatformUrl($attributes['platform_code']),
            'platform_id' => $this->generatePlatformId($attributes['platform_code']),
            'error_message' => null,
            'rejection_reason' => null,
            'platform_metadata' => $this->generatePlatformMetadata($attributes['platform_code'], 'live'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'live_date' => null,
            'platform_url' => null,
            'platform_id' => null,
            'error_message' => $this->faker->randomElement([
                'Audio quality does not meet platform requirements',
                'Network timeout during upload',
                'Invalid audio file format',
                'Metadata validation failed',
                'Platform API temporarily unavailable'
            ]),
            'retry_count' => $this->faker->numberBetween(1, 3),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'live_date' => null,
            'platform_url' => null,
            'platform_id' => null,
            'rejection_reason' => $this->faker->randomElement([
                'Copyright infringement detected',
                'Explicit content not properly labeled',
                'Audio quality below platform standards',
                'Metadata incomplete or incorrect',
                'Duplicate content already exists'
            ]),
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'removed',
            'removed_date' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'removal_reason' => $this->faker->randomElement([
                'Artist request',
                'Copyright claim',
                'Platform policy violation',
                'Technical issues',
                'End of distribution agreement'
            ]),
        ]);
    }

    public function spotify(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform_code' => 'spotify',
            'platform_name' => 'Spotify',
            'platform_url' => $attributes['status'] === 'live' ? $this->generatePlatformUrl('spotify') : null,
            'platform_id' => $attributes['status'] === 'live' ? $this->generatePlatformId('spotify') : null,
        ]);
    }

    public function appleMusic(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform_code' => 'apple_music',
            'platform_name' => 'Apple Music',
            'platform_url' => $attributes['status'] === 'live' ? $this->generatePlatformUrl('apple_music') : null,
            'platform_id' => $attributes['status'] === 'live' ? $this->generatePlatformId('apple_music') : null,
        ]);
    }

    public function withRevenue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'live',
            'live_date' => $this->faker->dateTimeBetween('-6 months', '-1 month'),
            'platform_metadata' => array_merge($attributes['platform_metadata'] ?? [], [
                'streams' => $this->faker->numberBetween(5000, 500000),
                'revenue' => $this->faker->randomFloat(2, 100, 10000),
                'listeners' => $this->faker->numberBetween(1000, 50000),
                'conversion_rate' => $this->faker->randomFloat(2, 0.01, 0.15),
                'top_countries' => $this->faker->randomElements([
                    'Uganda', 'Kenya', 'Tanzania', 'Nigeria', 'Ghana', 'South Africa', 'USA', 'UK'
                ], 3),
            ]),
        ]);
    }

    public function recentlyCreated(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'updated_at' => now(),
        ]);
    }
}