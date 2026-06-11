<?php

namespace Database\Factories\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\Song;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Settlement>
 */
class SettlementFactory extends Factory
{
    protected $model = Settlement::class;

    public function definition(): array
    {
        $gross = $this->faker->randomFloat(2, 1000, 100000);
        $fee = round($gross * 0.3, 2);

        return [
            'beneficiary_user_id' => User::factory(),
            'vertical' => Settlement::VERTICAL_STORE,
            'kind' => 'sale',
            'source_type' => Song::class,
            'source_id' => Song::factory(),
            'gross_ugx' => $gross,
            'fee_ugx' => $fee,
            'net_ugx' => round($gross - $fee, 2),
            'gross_credits' => 0,
            'fee_credits' => 0,
            'net_credits' => 0,
            'status' => Settlement::STATUS_PENDING,
            'hold_until' => null,
        ];
    }

    public function cleared(): static
    {
        return $this->state(fn () => [
            'status' => Settlement::STATUS_CLEARED,
            'cleared_at' => now(),
        ]);
    }
}
