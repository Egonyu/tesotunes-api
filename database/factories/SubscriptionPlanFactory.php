<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        $planTypes = [
            'basic' => [
                'name' => 'Basic Plan',
                'description' => 'Essential features for music streaming',
                'price_usd' => 4.99,
                'price_local' => 20000,
                'duration_days' => 30,
                'features' => [
                    'Unlimited streaming',
                    'Standard audio quality',
                    'Limited downloads (50 per month)',
                    'Basic playlists',
                    'Mobile app access'
                ],
                'limits' => [
                    'downloads_per_month' => 50,
                    'offline_playlists' => 3,
                    'audio_quality' => 'standard',
                    'ad_free' => false,
                ]
            ],
            'premium' => [
                'name' => 'Premium Plan',
                'description' => 'Full access with premium features',
                'price_usd' => 9.99,
                'price_local' => 40000,
                'duration_days' => 30,
                'features' => [
                    'Unlimited streaming',
                    'High-quality audio',
                    'Unlimited downloads',
                    'Advanced playlists',
                    'Ad-free experience',
                    'Offline listening',
                    'Cross-platform sync'
                ],
                'limits' => [
                    'downloads_per_month' => -1, // unlimited
                    'offline_playlists' => -1, // unlimited
                    'audio_quality' => 'high',
                    'ad_free' => true,
                ]
            ],
            'artist' => [
                'name' => 'Artist Plan',
                'description' => 'Professional tools for musicians',
                'price_usd' => 19.99,
                'price_local' => 80000,
                'duration_days' => 30,
                'features' => [
                    'All Premium features',
                    'Music distribution',
                    'Analytics dashboard',
                    'Revenue tracking',
                    'ISRC generation',
                    'Copyright management',
                    'Promotional tools'
                ],
                'limits' => [
                    'downloads_per_month' => -1,
                    'offline_playlists' => -1,
                    'audio_quality' => 'lossless',
                    'ad_free' => true,
                    'music_uploads_per_month' => 100,
                    'distribution_platforms' => -1,
                ]
            ],
            'label' => [
                'name' => 'Label Plan',
                'description' => 'Enterprise solution for record labels',
                'price_usd' => 49.99,
                'price_local' => 200000,
                'duration_days' => 30,
                'features' => [
                    'All Artist features',
                    'Multi-artist management',
                    'Bulk operations',
                    'Advanced analytics',
                    'White-label options',
                    'API access',
                    'Priority support'
                ],
                'limits' => [
                    'downloads_per_month' => -1,
                    'offline_playlists' => -1,
                    'audio_quality' => 'lossless',
                    'ad_free' => true,
                    'music_uploads_per_month' => -1,
                    'distribution_platforms' => -1,
                    'managed_artists' => -1,
                ]
            ]
        ];

        $planType = $this->faker->randomElement(array_keys($planTypes));
        $planData = $planTypes[$planType];

        return [
            'name' => $planData['name'],
            'description' => $planData['description'],
            'price_usd' => $planData['price_usd'],
            'price' => $planData['price_local'], // Use 'price' column instead of missing 'price_local'
            'currency' => 'UGX',
            'duration_days' => $planData['duration_days'],
            'features' => $planData['features'],
            'limits' => $planData['limits'],
            'type' => $planType,
            'is_active' => $this->faker->boolean(90),
            'is_popular' => $this->faker->boolean(20),
            'trial_days' => $this->faker->optional(0.7, 0)->numberBetween(7, 30),
            'metadata' => [
                'target_audience' => $this->faker->randomElement([
                    'general_listeners',
                    'music_enthusiasts',
                    'professional_artists',
                    'record_labels'
                ]),
                'marketing_tags' => $this->faker->randomElements([
                    'best_value',
                    'most_popular',
                    'professional',
                    'unlimited',
                    'ad_free',
                    'high_quality'
                ], $this->faker->numberBetween(1, 3)),
                'promo_eligible' => $this->faker->boolean(60),
            ],
            'sort_order' => $this->faker->numberBetween(1, 10),
            'created_at' => $this->faker->dateTimeBetween('-2 years', 'now'),
            'updated_at' => now(),
        ];
    }

    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Basic Plan',
            'description' => 'Essential features for music streaming',
            'price_usd' => 4.99,
            'price' => 20000,
            'type' => 'basic',
            'features' => [
                'Unlimited streaming',
                'Standard audio quality',
                'Limited downloads (50 per month)',
                'Basic playlists',
                'Mobile app access'
            ],
            'limits' => [
                'downloads_per_month' => 50,
                'offline_playlists' => 3,
                'audio_quality' => 'standard',
                'ad_free' => false,
            ],
            'is_popular' => false,
            'sort_order' => 1,
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Premium Plan',
            'description' => 'Full access with premium features',
            'price_usd' => 9.99,
            'price' => 40000,
            'type' => 'premium',
            'features' => [
                'Unlimited streaming',
                'High-quality audio',
                'Unlimited downloads',
                'Advanced playlists',
                'Ad-free experience',
                'Offline listening',
                'Cross-platform sync'
            ],
            'limits' => [
                'downloads_per_month' => -1,
                'offline_playlists' => -1,
                'audio_quality' => 'high',
                'ad_free' => true,
            ],
            'is_popular' => true,
            'sort_order' => 2,
        ]);
    }

    public function artist(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Artist Plan',
            'description' => 'Professional tools for musicians',
            'price_usd' => 19.99,
            'price' => 80000,
            'type' => 'artist',
            'features' => [
                'All Premium features',
                'Music distribution',
                'Analytics dashboard',
                'Revenue tracking',
                'ISRC generation',
                'Copyright management',
                'Promotional tools'
            ],
            'limits' => [
                'downloads_per_month' => -1,
                'offline_playlists' => -1,
                'audio_quality' => 'lossless',
                'ad_free' => true,
                'music_uploads_per_month' => 100,
                'distribution_platforms' => -1,
            ],
            'is_popular' => false,
            'sort_order' => 3,
        ]);
    }

    public function label(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Label Plan',
            'description' => 'Enterprise solution for record labels',
            'price_usd' => 49.99,
            'price' => 200000,
            'type' => 'label',
            'features' => [
                'All Artist features',
                'Multi-artist management',
                'Bulk operations',
                'Advanced analytics',
                'White-label options',
                'API access',
                'Priority support'
            ],
            'limits' => [
                'downloads_per_month' => -1,
                'offline_playlists' => -1,
                'audio_quality' => 'lossless',
                'ad_free' => true,
                'music_uploads_per_month' => -1,
                'distribution_platforms' => -1,
                'managed_artists' => -1,
            ],
            'is_popular' => false,
            'sort_order' => 4,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_popular' => true,
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'marketing_tags' => ['most_popular', 'best_value'],
            ]),
        ]);
    }

    public function withTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_days' => $this->faker->numberBetween(7, 30),
        ]);
    }

    public function withoutTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_days' => null,
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_days' => 30,
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_days' => 365,
            'price_usd' => $attributes['price_usd'] * 10, // 2 months free
            'price' => $attributes['price'] * 10,
            'name' => $attributes['name'] . ' (Yearly)',
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'billing_cycle' => 'yearly',
                'discount_percentage' => 16.67, // 2 months free = ~16.67% discount
            ]),
        ]);
    }

    public function discounted(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_usd' => $attributes['price_usd'] * 0.8, // 20% discount
            'price' => $attributes['price'] * 0.8,
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'discount_percentage' => 20,
                'promotional' => true,
                'promo_end_date' => $this->faker->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            ]),
        ]);
    }
}