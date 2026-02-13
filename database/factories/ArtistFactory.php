<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Artist>
 */
class ArtistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stageName = fake()->name();
        
        return [
            'user_id' => \App\Models\User::factory(),
            'stage_name' => $stageName,
            'slug' => \Illuminate\Support\Str::slug($stageName) . '-' . fake()->unique()->numberBetween(1, 10000),
            'bio' => fake()->paragraphs(2, true),
            'avatar' => 'artists/avatars/' . fake()->uuid() . '.jpg',
            'banner' => 'artists/banners/' . fake()->uuid() . '.jpg',
            'country' => 'Uganda',
            'city' => fake()->city(),
            'is_verified' => fake()->boolean(50),
            'verification_badge' => fake()->randomElement(['none', 'verified', 'featured', 'premium']),
            'verified_at' => fake()->boolean(50) ? now() : null,
            'status' => fake()->randomElement(['pending', 'active', 'suspended', 'rejected']),
            'total_plays' => fake()->numberBetween(0, 1000000),
            'total_songs' => fake()->numberBetween(0, 100),
            'total_albums' => fake()->numberBetween(0, 20),
            'total_revenue' => fake()->randomFloat(2, 0, 50000),
            'follower_count' => fake()->numberBetween(0, 10000),
        ];
    }

    /**
     * Indicate that the artist is pending verification.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => false,
            'verification_badge' => 'none',
            'verified_at' => null,
            'status' => 'pending',
            'total_songs' => 0,
            'total_albums' => 0,
            'total_plays' => 0,
            'total_revenue' => 0,
            'follower_count' => 0,
        ]);
    }

    /**
     * Indicate that the artist is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verification_badge' => 'verified',
            'verified_at' => now(),
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the artist is featured.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verification_badge' => 'featured',
            'verified_at' => now(),
            'status' => 'active',
        ]);
    }
}
