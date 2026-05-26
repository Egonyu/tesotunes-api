<?php

namespace Database\Factories;

use App\Models\Ad;
use App\Models\AdImpression;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdImpressionFactory extends Factory
{
    protected $model = AdImpression::class;

    public function definition(): array
    {
        return [
            'ad_id' => Ad::factory()->active()->image(),
            'placement_key' => $this->faker->randomElement([
                'web_top_banner', 'web_sidebar_top', 'web_in_feed_1', 'mobile_home_banner',
            ]),
            'user_id' => null,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'device_type' => $this->faker->randomElement(['desktop', 'mobile', 'tablet']),
            'page_url' => $this->faker->url(),
            'clicked' => false,
            'clicked_at' => null,
        ];
    }
}
