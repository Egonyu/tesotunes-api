<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\CatalogClaimRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CatalogClaimRequestFactory extends Factory
{
    protected $model = CatalogClaimRequest::class;

    public function definition(): array
    {
        return [
            'claimant_user_id' => User::factory(),
            'artist_id' => Artist::factory(),
            'requested_song_ids' => [],
            'phone_number' => fake()->phoneNumber(),
            'message' => fake()->sentence(),
            'evidence' => [fake()->sentence()],
            'status' => 'pending',
        ];
    }
}
