<?php

namespace Database\Factories;

use App\Models\Podcast;
use App\Models\Artist;
use App\Models\User;
use App\Models\PodcastCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PodcastFactory extends Factory
{
    protected $model = Podcast::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(3);
        $uuid = (string) Str::uuid();
        
        return [
            'artist_id' => Artist::factory(),
            'user_id' => User::factory(),
            'podcast_category_id' => PodcastCategory::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => $this->faker->paragraph(),
            'artwork' => $this->faker->optional()->imageUrl(640, 640),
            'rss_feed_url' => $this->faker->optional()->url(),
            'uuid' => $uuid,
            'rss_guid' => $uuid,
            'author_name' => $this->faker->name(),
            'copyright' => '© ' . $this->faker->year() . ' ' . $this->faker->name(),
            'tags' => $this->faker->optional()->words(3),
            'language' => $this->faker->randomElement(['en', 'sw', 'lg']),
            'is_explicit' => $this->faker->boolean(20),
            'status' => $this->faker->randomElement(['draft', 'published', 'archived']),
            'is_premium' => $this->faker->boolean(30),
            'is_monetized' => $this->faker->boolean(40),
            'monetization_type' => $this->faker->randomElement(['free', 'subscription', 'sponsorship', 'hybrid']),
            'subscription_price' => $this->faker->optional()->randomFloat(2, 5000, 50000),
            'accepts_sponsorship' => $this->faker->boolean(50),
            'total_revenue' => $this->faker->randomFloat(2, 0, 1000000),
            'monetized_at' => $this->faker->optional()->dateTimeBetween('-1 year', 'now'),
            'total_episodes' => $this->faker->numberBetween(0, 100),
            'total_listens' => $this->faker->numberBetween(0, 100000),
            'subscriber_count' => $this->faker->numberBetween(0, 10000),
            'total_listen_count' => $this->faker->numberBetween(0, 100000),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_premium' => true,
            'subscription_price' => $this->faker->randomFloat(2, 10000, 50000),
        ]);
    }

    public function monetized(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_monetized' => true,
            'monetized_at' => now(),
        ]);
    }
}
