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
            'title' => $this->faker->sentence().'?',
            'description' => $this->faker->optional()->paragraph(),
            'poll_type' => Poll::TYPE_GENERAL,
            'category' => $this->faker->optional()->randomKey(Poll::CATEGORIES),
            'audience' => Poll::AUDIENCE_ALL,
            'allow_guest_responses' => true,
            'show_results_before_completion' => true,
            'is_anonymous' => false,
            'credits_reward' => 3,
            'starts_at' => now(),
            'ends_at' => $this->faker->dateTimeBetween('now', '+30 days'),
            'total_responses' => 0,
            'status' => Poll::STATUS_ACTIVE,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => Poll::STATUS_DRAFT]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => Poll::STATUS_CLOSED]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => Poll::STATUS_ACTIVE,
            'ends_at' => now()->subHour(),
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn () => ['is_anonymous' => true]);
    }

    public function guestRestricted(): static
    {
        return $this->state(fn () => ['allow_guest_responses' => false]);
    }

    public function songBattle(): static
    {
        return $this->state(fn () => [
            'poll_type' => Poll::TYPE_SONG_BATTLE,
            'category' => 'song_battle',
        ]);
    }

    public function artistContest(): static
    {
        return $this->state(fn () => [
            'poll_type' => Poll::TYPE_ARTIST_CONTEST,
            'category' => 'artist_contest',
        ]);
    }

    public function researchSurvey(): static
    {
        return $this->state(fn () => [
            'poll_type' => Poll::TYPE_RESEARCH_SURVEY,
            'category' => 'research',
            'audience' => Poll::AUDIENCE_ALL,
            'ends_at' => $this->faker->dateTimeBetween('+7 days', '+90 days'),
        ]);
    }
}
