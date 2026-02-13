<?php

namespace Database\Factories\Modules\Forum;

use App\Models\Modules\Forum\ForumCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ForumCategoryFactory extends Factory
{
    protected $model = ForumCategory::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        
        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'icon' => $this->faker->randomElement(['💬', '🎵', '🎤', '🎧', '📻', '🎸', '🎹']),
            'color' => $this->faker->hexColor(),
            'order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
            'topics_count' => 0,
            'replies_count' => 0,
        ];
    }
}
