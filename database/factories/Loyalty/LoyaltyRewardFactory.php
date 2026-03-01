<?php

namespace Database\Factories\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyReward;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoyaltyRewardFactory extends Factory
{
    protected $model = LoyaltyReward::class;

    public function definition(): array
    {
        return [
            'loyalty_card_id' => LoyaltyCard::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'type' => $this->faker->randomElement(['content', 'merchandise', 'experience', 'discount', 'points']),
            'required_tier' => 'bronze',
            'points_amount' => $this->faker->randomElement([100, 250, 500, 1000]),
            'is_active' => true,
            'max_redemptions' => null,
            'current_redemptions' => 0,
            'available_from' => null,
            'available_until' => null,
        ];
    }

    public function content(): static
    {
        return $this->state(fn () => [
            'type' => 'content',
            'content_url' => $this->faker->url(),
        ]);
    }

    public function discount(): static
    {
        return $this->state(fn () => [
            'type' => 'discount',
            'discount_percentage' => $this->faker->randomElement([10, 15, 20, 25]),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function forGold(): static
    {
        return $this->state(fn () => [
            'required_tier' => 'gold',
            'points_amount' => 1000,
        ]);
    }
}
