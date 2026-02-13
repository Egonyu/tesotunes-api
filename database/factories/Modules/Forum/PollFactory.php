<?php

namespace Database\Factories\Modules\Forum;

use App\Models\User;
use App\Models\Modules\Forum\Poll;
use Illuminate\Database\Eloquent\Factories\Factory;

class PollFactory extends Factory
{
    protected $model = Poll::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pollable_type' => null,
            'pollable_id' => null,
            'question' => $this->faker->sentence() . '?',
            'description' => $this->faker->optional()->paragraph(),
            'allow_multiple_choices' => false,
            'show_results_before_vote' => false,
            'is_anonymous' => false,
            'starts_at' => null,
            'ends_at' => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'total_votes' => 0,
            'status' => 'active',
        ];
    }

    public function multipleChoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_multiple_choices' => true,
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_anonymous' => true,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }
}
