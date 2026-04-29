<?php

namespace Database\Factories\Modules\Forum;

use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PollResponseFactory extends Factory
{
    protected $model = PollResponse::class;

    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'poll_id' => Poll::factory(),
            'user_id' => User::factory(),
            'session_token' => null,
            'ip_address' => $this->faker->ipv4(),
            'is_complete' => true,
            'started_at' => $startedAt,
            'completed_at' => $this->faker->dateTimeBetween($startedAt, 'now'),
        ];
    }

    public function guest(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'session_token' => Str::random(64),
        ]);
    }

    public function incomplete(): static
    {
        return $this->state(fn () => [
            'is_complete' => false,
            'completed_at' => null,
        ]);
    }
}
