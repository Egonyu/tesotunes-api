<?php

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ArtistProfile Factory - Alias for Artist Factory
 * 
 * This factory exists to support legacy test references to ArtistProfile
 * which should actually use the Artist model.
 * 
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Artist>
 */
class ArtistProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Artist::class;

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
            'cover_image' => 'artists/covers/' . fake()->uuid() . '.jpg',
            'is_verified' => fake()->boolean(50),
            'is_trusted' => fake()->boolean(10),
            'verification_status' => fake()->randomElement(['pending', 'verified', 'rejected']),
            'verified_at' => fake()->boolean(50) ? now() : null,
            'status' => fake()->randomElement(['active', 'suspended', 'banned']),
            'can_upload' => true,
            'total_songs_count' => fake()->numberBetween(0, 100),
            'total_albums_count' => fake()->numberBetween(0, 20),
            'total_plays_count' => fake()->numberBetween(0, 1000000),
            'total_plays_cached' => fake()->numberBetween(0, 1000000),
            'total_revenue' => fake()->randomFloat(2, 0, 50000),
            'total_revenue_cached' => fake()->randomFloat(2, 0, 50000),
            'followers_count' => fake()->numberBetween(0, 10000),
        ];
    }

    /**
     * Indicate that the artist is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    /**
     * Indicate that the artist is pending verification.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => false,
            'verification_status' => 'pending',
            'verified_at' => null,
        ]);
    }

    /**
     * Indicate that the artist is a trusted artist.
     */
    public function trusted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_trusted' => true,
            'is_verified' => true,
            'verification_status' => 'verified',
        ]);
    }
}
