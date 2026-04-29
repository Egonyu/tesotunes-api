<?php

namespace Database\Factories\Modules\Forum;

use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

class PollQuestionFactory extends Factory
{
    protected $model = PollQuestion::class;

    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'position' => 0,
            'question_text' => $this->faker->sentence().'?',
            'description' => $this->faker->optional()->sentence(),
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'is_required' => true,
            'allow_multiple' => false,
            'song_id' => null,
            'artist_id' => null,
            'settings' => null,
        ];
    }

    public function freeText(): static
    {
        return $this->state(fn () => ['question_type' => PollQuestion::TYPE_FREE_TEXT]);
    }

    public function rating(): static
    {
        return $this->state(fn () => [
            'question_type' => PollQuestion::TYPE_RATING,
            'settings' => ['scale_min' => 1, 'scale_max' => 10],
        ]);
    }

    public function likert(): static
    {
        return $this->state(fn () => [
            'question_type' => PollQuestion::TYPE_LIKERT,
            'settings' => [
                'scale_min' => 1,
                'scale_max' => 5,
                'min_label' => 'Strongly Disagree',
                'max_label' => 'Strongly Agree',
            ],
        ]);
    }

    public function ranking(): static
    {
        return $this->state(fn () => ['question_type' => PollQuestion::TYPE_RANKING]);
    }

    public function optional(): static
    {
        return $this->state(fn () => ['is_required' => false]);
    }
}
