<?php

namespace Database\Factories\Modules\Forum;

use App\Models\Modules\Forum\Poll;
use App\Models\User;
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
            'title' => $this->faker->sentence().'?',
            'description' => $this->faker->optional()->paragraph(),
            'poll_type' => Poll::TYPE_GENERAL,
            'category' => null,
            'credits_reward' => 3,
            'allow_multiple_votes' => false,
            'show_results_before_vote' => false,
            'is_anonymous' => false,
            'starts_at' => null,
            'ends_at' => $this->faker->dateTimeBetween('now', '+30 days'),
            'total_votes' => 0,
            'status' => 'active',
        ];
    }

    public function multipleChoice(): static
    {
        return $this->state(fn () => ['allow_multiple_votes' => true]);
    }

    public function anonymous(): static
    {
        return $this->state(fn () => ['is_anonymous' => true]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'closed']);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'ends_at' => now()->subHour(),
        ]);
    }

    public function songBattle(): static
    {
        return $this->state(fn () => ['poll_type' => Poll::TYPE_SONG_BATTLE]);
    }

    public function artistContest(): static
    {
        return $this->state(fn () => ['poll_type' => Poll::TYPE_ARTIST_CONTEST]);
    }
}
