<?php

namespace Database\Factories;

use App\Modules\Store\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Store\Models\ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Merchandise',
            'T-Shirts',
            'Hoodies',
            'Accessories',
            'Digital Downloads',
            'Beats & Instrumentals',
            'Sample Packs',
            'Services',
            'Music Production',
            'Mixing & Mastering',
            'Experiences',
            'Meet & Greet',
            'Studio Visits',
            'Promotions',
            'Radio Mentions',
            'Playlist Features',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->numberBetween(100, 999),
            'description' => fake()->sentence(10),
            'icon' => null,
            'parent_id' => null,
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }

    /**
     * Category is inactive
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Category is a subcategory
     */
    public function subcategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => ProductCategory::factory(),
        ]);
    }
}
