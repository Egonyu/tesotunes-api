<?php

namespace Database\Factories\Modules\Forum;

use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

class PollOptionFactory extends Factory
{
    protected $model = PollOption::class;

    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'option_text' => $this->faker->words(3, true),
            'image_url' => null,
            'votes_count' => 0,
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }
}
