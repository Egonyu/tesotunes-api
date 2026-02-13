<?php

namespace Database\Factories;

use App\Models\PodcastSubscription;
use App\Models\Podcast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PodcastSubscriptionFactory extends Factory
{
    protected $model = PodcastSubscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'podcast_id' => Podcast::factory(),
            'type' => $this->faker->randomElement(['free', 'premium']),
            'status' => $this->faker->randomElement(['active', 'cancelled', 'expired', 'paused']),
            'price_paid' => $this->faker->optional()->randomFloat(2, 5000, 50000),
            'payment_method' => $this->faker->optional()->randomElement(['mobile_money', 'bank_transfer', 'card']),
            'transaction_id' => $this->faker->optional()->uuid(),
            'subscribed_at' => now(),
            'expires_at' => $this->faker->optional()->dateTimeBetween('now', '+1 year'),
            'cancelled_at' => null,
            'renewed_at' => null,
            'auto_renew' => $this->faker->boolean(50),
            'next_billing_date' => $this->faker->optional()->dateTimeBetween('now', '+1 month'),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'subscribed_at' => now(),
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'premium',
            'price_paid' => 20000,
            'expires_at' => now()->addMonth(),
            'next_billing_date' => now()->addMonth(),
        ]);
    }
}
