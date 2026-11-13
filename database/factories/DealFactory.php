<?php

namespace Database\Factories;

use App\Modules\Store\Models\Deal;
use App\Modules\Store\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        $title = fake()->randomElement(['Two rolexes for one', 'Free chai with breakfast', 'Half-price haircut', 'Buy two sodas, get one']);

        return [
            'uuid' => (string) Str::uuid(),
            'store_id' => Store::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'discount_label' => '2 for 1',
            'terms' => 'Show the code at the counter. One claim per person.',
            'town' => 'Soroti',
            'credits_price' => 20,
            'ugx_value' => 4000,
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addDays(3),
            'max_claims' => 40,
            'claims_count' => 0,
            'redemptions_count' => 0,
            'status' => Deal::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => Deal::STATUS_DRAFT, 'published_at' => null]);
    }

    public function free(): static
    {
        return $this->state(fn () => ['credits_price' => 0]);
    }
}
