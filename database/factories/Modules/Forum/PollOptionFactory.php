<?php

namespace Database\Factories\Modules\Forum;

use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

class PollOptionFactory extends Factory
{
    protected $model = PollOption::class;

    public function definition(): array
    {
        return [
            'question_id' => PollQuestion::factory(),
            'option_text' => $this->faker->sentence(3),
            'image' => null,
            'position' => 0,
            'song_id' => null,
            'artist_id' => null,
            'response_count' => 0,
        ];
    }
}
