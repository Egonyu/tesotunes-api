<?php

namespace Database\Factories\Modules\Forum;

use App\Models\User;
use App\Models\Modules\Forum\ForumReply;
use App\Models\Modules\Forum\ForumTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

class ForumReplyFactory extends Factory
{
    protected $model = ForumReply::class;

    public function definition(): array
    {
        return [
            'topic_id' => ForumTopic::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'content' => $this->faker->paragraphs(2, true),
            'likes_count' => $this->faker->numberBetween(0, 50),
            'is_solution' => false,
            'is_highlighted' => false,
        ];
    }

    public function solution(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_solution' => true,
        ]);
    }

    public function highlighted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_highlighted' => true,
        ]);
    }
}
