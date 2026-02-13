<?php

namespace Database\Factories;

use App\Modules\Store\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Store\Models\Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();
        $user = User::factory()->create();
        
        return [
            'uuid' => Str::uuid(),
            'user_id' => $user->id,
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->paragraph(3),
            'logo' => null,
            'banner' => null,
            'store_type' => fake()->randomElement(['artist', 'user']),
            'status' => 'active',
            'is_verified' => false,
            'verified_at' => null,
            'suspended_at' => null,
            'suspended_reason' => null,
            'settings' => [
                'accept_orders' => true,
                'auto_accept_orders' => false,
                'allow_reviews' => true,
                'shipping_policy' => 'Standard shipping within Uganda',
                'return_policy' => '7-day return policy',
            ],
        ];
    }

    /**
     * Indicate that the store is in draft status.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Indicate that the store is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_reason' => 'Suspended by admin',
        ]);
    }

    /**
     * Indicate that the store is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }

    /**
     * Indicate that the store is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    /**
     * Indicate that the store is an artist store.
     */
    public function artist(): static
    {
        return $this->state(fn (array $attributes) => [
            'store_type' => 'artist',
        ]);
    }

    /**
     * Indicate that the store has premium subscription.
     */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'store_type' => 'business',
        ]);
    }

    /**
     * Indicate that the store has business subscription.
     */
    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'store_type' => 'business',
        ]);
    }

    /**
     * Create a store with specific owner
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
