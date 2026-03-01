<?php

namespace Database\Factories\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoyaltyCardMemberFactory extends Factory
{
    protected $model = LoyaltyCardMember::class;

    public function definition(): array
    {
        $joinedAt = $this->faker->dateTimeBetween('-6 months', 'now');

        return [
            'loyalty_card_id' => LoyaltyCard::factory(),
            'user_id' => User::factory(),
            'tier' => 'bronze',
            'status' => 'active',
            'subscription_type' => $this->faker->randomElement(['monthly', 'yearly']),
            'auto_renew' => true,
            'price_paid' => 5000,
            'payment_method' => 'mobile_money',
            'joined_at' => $joinedAt,
            'expires_at' => now()->addMonth(),
            'renewed_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'expires_at' => now()->subDays(5),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
        ]);
    }

    public function silver(): static
    {
        return $this->state(fn () => [
            'tier' => 'silver',
            'price_paid' => 10000,
        ]);
    }

    public function gold(): static
    {
        return $this->state(fn () => [
            'tier' => 'gold',
            'price_paid' => 25000,
        ]);
    }

    public function platinum(): static
    {
        return $this->state(fn () => [
            'tier' => 'platinum',
            'price_paid' => 50000,
        ]);
    }
}
