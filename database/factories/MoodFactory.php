<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Mood>
 */
class MoodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $moods = ['Happy', 'Sad', 'Energetic', 'Relaxed', 'Romantic', 'Angry', 'Peaceful', 'Uplifting'];
        $name = fake()->randomElement($moods);
        
        return [
            'name' => $name . ' ' . fake()->unique()->numberBetween(1, 1000),
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 10000),
            'description' => fake()->sentence(),
            'icon' => 'mood-icon',
            'color' => fake()->hexColor(),
            'is_active' => true,
        ];
    }
}
