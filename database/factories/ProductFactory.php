<?php

namespace Database\Factories;

use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Store\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);
        
        return [
            'uuid' => Str::uuid(),
            'store_id' => Store::factory(),
            'category_id' => ProductCategory::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'sku' => strtoupper(fake()->bothify('??-####')),
            'description' => fake()->paragraphs(3, true),
            'short_description' => fake()->sentence(20),
            'images' => [],
            'featured_image' => null,
            'product_type' => fake()->randomElement(['physical', 'digital', 'service']),
            'status' => 'active',
            'is_featured' => false,
            'is_taxable' => true,
            'has_variants' => false,
            'published_at' => null,
            'view_count' => 0,
        ];
    }

    /**
     * Configure the factory to auto-create related models
     */
    public function configure()
    {
        return $this->afterMaking(function (Product $product) {
            // Store pricing/inventory attributes temporarily
            // They'll be used in afterCreating
        })->afterCreating(function (Product $product) {
            // Get pricing data from attributes if provided during factory creation
            $attributes = $product->getAttributes();
            $priceUgx = $attributes['price_ugx'] ?? fake()->numberBetween(5000, 500000);
            $priceCredits = $attributes['price_credits'] ?? (fake()->boolean(70) ? (int)($priceUgx / 100) : null);
            
            // Create pricing only if it doesn't already exist
            if (!\App\Modules\Store\Models\ProductPricing::where('product_id', $product->id)->exists()) {
                \App\Modules\Store\Models\ProductPricing::create([
                    'product_id' => $product->id,
                    'currency_type' => $priceCredits ? 'both' : 'ugx',
                    'price_ugx' => $priceUgx,
                    'price_credits' => $priceCredits,
                    'compare_at_price_ugx' => $attributes['compare_at_price_ugx'] ?? (fake()->boolean(30) ? $priceUgx * 1.2 : null),
                    'compare_at_price_credits' => $attributes['compare_at_price_credits'] ?? null,
                    'discount_percentage' => 0,
                    'accepts_credits' => (bool)$priceCredits,
                    'allow_hybrid_payment' => $attributes['allow_hybrid_payment'] ?? (bool)$priceCredits,
                ]);
            }

            // Create inventory only if it doesn't already exist
            if (!\App\Modules\Store\Models\ProductInventory::where('product_id', $product->id)->exists()) {
                $stockQty = $attributes['inventory_quantity'] ?? $attributes['stock_quantity'] ?? fake()->numberBetween(0, 100);
                $trackInventory = $attributes['track_inventory'] ?? in_array($product->product_type, ['physical']);
                
                \App\Modules\Store\Models\ProductInventory::create([
                    'product_id' => $product->id,
                    'track_inventory' => $trackInventory ? 'track' : 'dont_track',
                    'stock_quantity' => $stockQty,
                    'reserved_quantity' => 0,
                    'available_quantity' => $stockQty,
                    'low_stock_threshold' => 5,
                    'allow_backorder' => $attributes['allow_backorder'] ?? false,
                    'is_in_stock' => $stockQty > 0,
                ]);
            }

            // Create physical specs for physical products
            if ($product->product_type === 'physical' || isset($attributes['requires_shipping'])) {
                \App\Modules\Store\Models\ProductPhysicalSpecs::create([
                    'product_id' => $product->id,
                    'weight' => $attributes['weight'] ?? fake()->randomFloat(2, 0.1, 5.0),
                    'length' => $attributes['dimensions']['length'] ?? fake()->numberBetween(10, 50),
                    'width' => $attributes['dimensions']['width'] ?? fake()->numberBetween(10, 50),
                    'height' => $attributes['dimensions']['height'] ?? fake()->numberBetween(5, 30),
                    'requires_shipping' => $attributes['requires_shipping'] ?? true,
                    'is_fragile' => fake()->boolean(20),
                    'shipping_class' => 'standard',
                ]);
            }

            // Create SEO data
            \App\Modules\Store\Models\ProductSeo::create([
                'product_id' => $product->id,
                'meta_title' => $attributes['meta_title'] ?? $product->name,
                'meta_description' => $attributes['meta_description'] ?? fake()->sentence(15),
                'meta_keywords' => fake()->words(5),
            ]);
        });
    }

    /**
     * Product is digital
     */
    public function digital(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type' => 'digital',
        ]);
    }

    /**
     * Product is a service
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type' => 'service',
        ]);
    }

    /**
     * Product is an experience
     */
    public function experience(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type' => 'experience',
        ]);
    }

    /**
     * Product is out of stock
     */
    public function outOfStock(): static
    {
        return $this->afterCreating(function (Product $product) {
            $product->inventory()->update([
                'stock_quantity' => 0,
                'available_quantity' => 0,
                'is_in_stock' => false,
                'allow_backorder' => false,
            ]);
        });
    }

    /**
     * Product is featured
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Product is draft
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Product only accepts credits
     */
    public function creditsOnly(): static
    {
        return $this->afterCreating(function (Product $product) {
            $product->pricing()->update([
                'currency_type' => 'credits',
                'price_ugx' => null,
                'price_credits' => fake()->numberBetween(100, 5000),
                'accepts_credits' => true,
                'allow_hybrid_payment' => false,
            ]);
        });
    }
}
