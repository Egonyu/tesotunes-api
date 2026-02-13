<?php

namespace Database\Factories\Modules\Forum;

use App\Models\User;
use App\Models\Modules\Forum\ForumTopic;
use App\Models\Modules\Forum\ForumCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ForumTopicFactory extends Factory
{
    protected $model = ForumTopic::class;

    public function definition(): array
    {
        $title = $this->faker->sentence();
        
        return [
            'category_id' => ForumCategory::factory(),
            'user_id' => User::factory(),
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title) . '-' . Str::random(6),
            'content' => $this->faker->paragraphs(3, true),
            'is_pinned' => false,
            'is_locked' => false,
            'is_featured' => false,
            'status' => 'active',
            'views_count' => $this->faker->numberBetween(0, 1000),
            'replies_count' => 0,
            'likes_count' => $this->faker->numberBetween(0, 100),
            'last_reply_user_id' => null,
            'last_activity_at' => now(),
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_pinned' => true,
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_locked' => true,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }
}
