<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MusicUpload>
 */
class MusicUploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_name' => fake()->uuid() . '.mp3',
            'file_path' => 'uploads/music/' . fake()->uuid() . '.mp3',
            'file_size' => fake()->numberBetween(1000000, 50000000), // 1MB to 50MB
            'mime_type' => 'audio/mpeg',
            'duration' => fake()->numberBetween(120, 360),
            'status' => fake()->randomElement(['uploaded', 'processing', 'completed', 'failed']),
            'storage_disk' => fake()->randomElement(['local', 'digitalocean']),
        ];
    }
}
