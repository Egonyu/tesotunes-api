<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Genre>
 */
class GenreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $genres = ['Afrobeat', 'Kadongo Kamu', 'Hip Hop', 'R&B', 'Gospel', 'Reggae', 'Dancehall', 'Pop'];
        $name = fake()->randomElement($genres);
        
        return [
            'name' => $name . ' ' . fake()->unique()->numberBetween(1, 1000),
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 10000),
            'description' => fake()->sentence(),
            'icon' => 'genres/' . fake()->uuid() . '.jpg',
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
