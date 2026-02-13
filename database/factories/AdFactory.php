<?php

namespace Database\Factories;

use App\Models\Ad;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdFactory extends Factory
{
    protected $model = Ad::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['google_adsense', 'direct', 'affiliate']);
        
        return [
            'name' => $this->faker->sentence(3),
            'type' => $type,
            'placement' => $this->faker->randomElement(['header', 'sidebar', 'inline', 'footer', 'between_content', 'popup']),
            'format' => $this->faker->randomElement(['banner', 'square', 'rectangle', 'native', 'video']),
            
            // AdSense specific (if type is google_adsense)
            'adsense_slot_id' => $type === 'google_adsense' ? $this->faker->numerify('##########') : null,
            'adsense_format' => $type === 'google_adsense' ? $this->faker->randomElement(['auto', 'rectangle', 'horizontal', 'vertical']) : null,
            
            // Direct ad specific (if type is direct or affiliate)
            'html_code' => in_array($type, ['direct', 'affiliate']) ? '<div class="ad">' . $this->faker->sentence() . '</div>' : null,
            'image_url' => in_array($type, ['direct', 'affiliate']) ? $this->faker->imageUrl(728, 90) : null,
            'link_url' => in_array($type, ['direct', 'affiliate']) ? $this->faker->url() : null,
            'advertiser_name' => in_array($type, ['direct', 'affiliate']) ? $this->faker->company() : null,
            
            // Targeting
            'pages' => $this->faker->randomElement([
                [],
                ['home'],
                ['home', 'discover'],
                ['artist', 'genres'],
            ]),
            'user_tiers' => $this->faker->randomElement([
                ['free'],
                ['free', 'premium'],
                null,
            ]),
            'mobile_only' => $this->faker->boolean(20),
            'desktop_only' => $this->faker->boolean(20),
            
            // Scheduling
            'start_date' => $this->faker->optional(0.3)->dateTimeBetween('-1 month', 'now'),
            'end_date' => $this->faker->optional(0.3)->dateTimeBetween('now', '+3 months'),
            
            // Analytics
            'impressions' => $this->faker->numberBetween(100, 50000),
            'clicks' => $this->faker->numberBetween(5, 1000),
            'revenue' => $this->faker->randomFloat(2, 1000, 100000),
            
            // Settings
            'is_active' => $this->faker->boolean(80),
            'priority' => $this->faker->numberBetween(0, 100),
        ];
    }
    
    /**
     * Active ad state
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }
    
    /**
     * Inactive ad state
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
    
    /**
     * High performing ad
     */
    public function highPerforming(): static
    {
        return $this->state(fn (array $attributes) => [
            'impressions' => $this->faker->numberBetween(10000, 100000),
            'clicks' => $this->faker->numberBetween(500, 5000),
            'revenue' => $this->faker->randomFloat(2, 50000, 500000),
        ]);
    }
    
    /**
     * Google AdSense ad
     */
    public function adsense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'google_adsense',
            'adsense_slot_id' => $this->faker->numerify('##########'),
            'adsense_format' => $this->faker->randomElement(['auto', 'rectangle', 'horizontal']),
            'html_code' => null,
            'image_url' => null,
            'link_url' => null,
            'advertiser_name' => null,
        ]);
    }
    
    /**
     * Direct ad
     */
    public function direct(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'direct',
            'image_url' => $this->faker->imageUrl(728, 90),
            'link_url' => $this->faker->url(),
            'advertiser_name' => $this->faker->company(),
            'adsense_slot_id' => null,
            'adsense_format' => null,
        ]);
    }
    
    /**
     * Mobile only ad
     */
    public function mobileOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'mobile_only' => true,
            'desktop_only' => false,
        ]);
    }
    
    /**
     * Desktop only ad
     */
    public function desktopOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'mobile_only' => false,
            'desktop_only' => true,
        ]);
    }
}
