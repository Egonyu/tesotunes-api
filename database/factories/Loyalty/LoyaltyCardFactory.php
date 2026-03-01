<?php

namespace Database\Factories\Loyalty;

use App\Models\Artist;
use App\Models\Loyalty\LoyaltyCard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LoyaltyCardFactory extends Factory
{
    protected $model = LoyaltyCard::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true).' Fan Club';

        return [
            'uuid' => Str::uuid()->toString(),
            'artist_id' => Artist::factory(),
            'name' => $name,
            'description' => $this->faker->paragraphs(2, true),
            'logo_url' => null,
            'banner_url' => null,
            'primary_color' => $this->faker->hexColor(),
            'secondary_color' => $this->faker->hexColor(),
            'tiers' => [
                [
                    'name' => 'bronze',
                    'price_monthly' => 5000,
                    'price_yearly' => 50000,
                    'benefits' => ['Early access to new releases', 'Fan club badge'],
                ],
                [
                    'name' => 'silver',
                    'price_monthly' => 10000,
                    'price_yearly' => 100000,
                    'benefits' => ['All Bronze perks', 'Exclusive content', 'Priority support'],
                ],
                [
                    'name' => 'gold',
                    'price_monthly' => 25000,
                    'price_yearly' => 250000,
                    'benefits' => ['All Silver perks', 'Meet & greet access', 'Signed merch'],
                ],
            ],
            'allow_monthly' => true,
            'allow_yearly' => true,
            'auto_renew' => true,
            'status' => 'active',
            'total_members' => 0,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => 'suspended',
        ]);
    }

    public function withSingleTier(): static
    {
        return $this->state(fn () => [
            'tiers' => [
                [
                    'name' => 'bronze',
                    'price_monthly' => 5000,
                    'price_yearly' => 50000,
                    'benefits' => ['Basic fan club access'],
                ],
            ],
        ]);
    }
}
