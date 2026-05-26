<?php

namespace Database\Factories;

use App\Models\Ad;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdFactory extends Factory
{
    protected $model = Ad::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['image', 'html', 'audio', 'native', 'google_adsense']);

        return [
            'title' => $this->faker->sentence(4),
            'advertiser_name' => $this->faker->company(),
            'type' => $type,
            'format' => $this->faker->randomElement([
                'banner_728x90', 'banner_320x50', 'square_300x250', 'native', 'audio', 'html',
            ]),

            // Image ad
            'image_url' => in_array($type, ['image', 'native']) ? $this->faker->imageUrl(728, 90) : null,
            'click_url' => in_array($type, ['image', 'native', 'html']) ? $this->faker->url() : null,
            'cta_text' => in_array($type, ['image', 'native']) ? $this->faker->randomElement(['Learn More', 'Shop Now', 'Listen Now', 'Get it Free']) : null,

            // HTML ad
            'html_content' => $type === 'html' ? '<div class="ad-placeholder">'.$this->faker->sentence().'</div>' : null,

            // Audio ad
            'audio_url' => $type === 'audio' ? $this->faker->url() : null,
            'audio_duration_seconds' => $type === 'audio' ? $this->faker->numberBetween(10, 30) : null,

            // Native ad
            'native_headline' => $type === 'native' ? $this->faker->sentence(6) : null,
            'native_body' => $type === 'native' ? $this->faker->sentence(15) : null,
            'native_image_url' => $type === 'native' ? $this->faker->imageUrl(400, 300) : null,

            // Google AdSense
            'adsense_slot_id' => $type === 'google_adsense' ? $this->faker->numerify('##########') : null,
            'adsense_format' => $type === 'google_adsense' ? $this->faker->randomElement(['auto', 'rectangle', 'horizontal', 'vertical']) : null,

            // Scheduling
            'is_active' => $this->faker->boolean(80),
            'starts_at' => $this->faker->optional(0.3)->dateTimeBetween('-1 month', 'now'),
            'ends_at' => $this->faker->optional(0.3)->dateTimeBetween('now', '+3 months'),

            // Budget (UGX)
            'total_budget_ugx' => $this->faker->optional(0.6)->randomFloat(2, 50000, 5000000),
            'daily_budget_ugx' => $this->faker->optional(0.6)->randomFloat(2, 5000, 200000),
            'cost_per_impression_ugx' => $this->faker->optional(0.5)->randomFloat(4, 1, 50),
            'cost_per_click_ugx' => $this->faker->optional(0.5)->randomFloat(2, 100, 5000),

            // Targeting
            'target_tiers' => $this->faker->randomElement([['free'], ['free', 'premium_basic'], null]),
            'target_devices' => $this->faker->randomElement([['desktop'], ['mobile'], ['desktop', 'mobile'], null]),
            'target_countries' => $this->faker->randomElement([['UG'], ['UG', 'KE', 'TZ'], null]),

            'priority' => $this->faker->numberBetween(1, 10),
            'notes' => $this->faker->optional(0.3)->sentence(),
            'created_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true, 'starts_at' => null, 'ends_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'type' => 'image',
            'format' => 'banner_728x90',
            'image_url' => $this->faker->imageUrl(728, 90),
            'click_url' => $this->faker->url(),
            'cta_text' => 'Learn More',
            'html_content' => null,
            'audio_url' => null,
        ]);
    }

    public function audio(): static
    {
        return $this->state(fn () => [
            'type' => 'audio',
            'format' => 'audio',
            'audio_url' => $this->faker->url(),
            'audio_duration_seconds' => $this->faker->numberBetween(10, 30),
            'image_url' => null,
            'html_content' => null,
        ]);
    }

    public function adsense(): static
    {
        return $this->state(fn () => [
            'type' => 'google_adsense',
            'adsense_slot_id' => $this->faker->numerify('##########'),
            'adsense_format' => 'auto',
            'image_url' => null,
            'html_content' => null,
            'audio_url' => null,
        ]);
    }

    public function freeOnly(): static
    {
        return $this->state(fn () => ['target_tiers' => ['free']]);
    }

    public function mobileOnly(): static
    {
        return $this->state(fn () => ['target_devices' => ['mobile']]);
    }

    public function desktopOnly(): static
    {
        return $this->state(fn () => ['target_devices' => ['desktop']]);
    }

    public function ugandaOnly(): static
    {
        return $this->state(fn () => ['target_countries' => ['UG']]);
    }
}
