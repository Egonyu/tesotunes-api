<?php

namespace Database\Factories;

use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Store\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 50, 1000);
        $quantity = fake()->numberBetween(1, 5);
        $subtotal = $unitPrice * $quantity;
        $taxAmount = 0;
        
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(3, true),
            'product_description' => fake()->sentence(),
            'product_image' => null,
            'product_variant' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $subtotal + $taxAmount,
            'download_url' => null,
            'download_count' => 0,
            'download_expires_at' => null,
            // Verification fields (for promotional products)
            'verification_status' => 'pending',
            'verification_url' => null,
            'verification_notes' => null,
            'verification_submitted_at' => null,
            'verified_at' => null,
            'verification_expires_at' => null,
            'rejection_reason' => null,
            'dispute_reason' => null,
        ];
    }

    /**
     * Digital product item
     */
    public function digital(): static
    {
        return $this->state(fn (array $attributes) => [
            'download_url' => fake()->url(),
            'download_count' => 0,
            'download_expires_at' => now()->addDays(30),
        ]);
    }

    /**
     * Promotional product item
     */
    public function promotion(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => 'pending',
            'verification_expires_at' => now()->addHours(72),
        ]);
    }

    /**
     * Verification submitted
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => 'submitted',
            'verification_url' => fake()->url(),
            'verification_notes' => fake()->sentence(),
            'verification_submitted_at' => now(),
        ]);
    }

    /**
     * Verification completed
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => 'verified',
            'verification_url' => fake()->url(),
            'verification_submitted_at' => now()->subDays(1),
            'verified_at' => now(),
        ]);
    }

    /**
     * Verification rejected
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => 'rejected',
            'verification_url' => fake()->url(),
            'verification_submitted_at' => now()->subDays(1),
            'rejection_reason' => fake()->sentence(),
        ]);
    }
}
