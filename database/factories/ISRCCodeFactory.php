<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\ISRCCode;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

class ISRCCodeFactory extends Factory
{
    protected $model = ISRCCode::class;

    public function definition(): array
    {
        $countryCode = $this->faker->randomElement(['UG', 'KE', 'TZ', 'RW']);
        $registrantCode = strtoupper($this->faker->lexify('???'));
        $yearCode = str_pad($this->faker->numberBetween(20, 30), 2, '0', STR_PAD_LEFT);
        $designationCode = str_pad($this->faker->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT);

        return [
            'song_id' => Song::factory(),
            'artist_id' => Artist::factory(),
            'code' => $countryCode.$registrantCode.$yearCode.$designationCode,
            'country_code' => $countryCode,
            'registrant_code' => $registrantCode,
            'year_code' => $yearCode,
            'designation_code' => $designationCode,
            'status' => $this->faker->randomElement(['active', 'inactive', 'disputed']),
            'registration_authority' => 'Uganda Registration Authority',
            'registration_reference' => $this->faker->optional(0.7)->bothify('URA-####-????'),
            'cleared_for_distribution' => $this->faker->boolean(60),
            'distribution_cleared_at' => $this->faker->optional(0.6)->dateTimeBetween('-6 months', 'now'),
            'registered_at' => $this->faker->optional(0.7)->dateTimeBetween('-1 year', 'now'),
            'is_verified' => $this->faker->boolean(50),
            'verified_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'registered_at' => null,
            'registration_reference' => null,
            'cleared_for_distribution' => false,
            'distribution_cleared_at' => null,
        ]);
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'registered_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'registration_reference' => 'URA-'.$this->faker->numerify('####').'-'.$this->faker->lexify('????'),
        ]);
    }

    public function clearedForDistribution(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'registered_at' => $this->faker->dateTimeBetween('-1 year', '-1 month'),
            'registration_reference' => 'URA-'.$this->faker->numerify('####').'-'.$this->faker->lexify('????'),
            'cleared_for_distribution' => true,
            'distribution_cleared_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    public function ugandanCode(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'UG',
        ]);
    }
}
