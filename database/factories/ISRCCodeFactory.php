<?php

namespace Database\Factories;

use App\Models\ISRCCode;
use App\Models\Song;
use App\Models\Artist;
use App\Models\Album;
use Illuminate\Database\Eloquent\Factories\Factory;

class ISRCCodeFactory extends Factory
{
    protected $model = ISRCCode::class;

    public function configure()
    {
        return $this->afterMaking(function (ISRCCode $isrcCode) {
            // Reconstruct isrc_code from individual components to ensure consistency
            $isrcCode->isrc_code = $isrcCode->country_code . 
                                   $isrcCode->registrant_code . 
                                   $isrcCode->year_code . 
                                   $isrcCode->designation_code;
        });
    }

    public function definition(): array
    {
        $countryCode = $this->faker->randomElement(['UG', 'KE', 'TZ', 'RW']);
        $registrantCode = strtoupper($this->faker->lexify('???'));
        $yearCode = $this->faker->numberBetween(20, 30);
        $designationCode = str_pad($this->faker->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT);

        return [
            'isrc_code' => $countryCode . $registrantCode . str_pad($yearCode, 2, '0', STR_PAD_LEFT) . $designationCode,
            'song_id' => Song::factory(),
            'artist_id' => Artist::factory(),
            'album_id' => null, // Make nullable by default
            'country_code' => $countryCode,
            'registrant_code' => $registrantCode,
            'year_code' => str_pad($yearCode, 2, '0', STR_PAD_LEFT),
            'designation_code' => $designationCode,
            'registrant_name' => $this->faker->company(),
            'recording_date' => $this->faker->dateTimeBetween('-5 years', 'now'),
            'recording_location' => $this->faker->city() . ', Uganda',
            'recording_details' => [
                'studio' => $this->faker->company() . ' Studios',
                'engineer' => $this->faker->name(),
                'producer' => $this->faker->name(),
                'equipment' => $this->faker->randomElements([
                    'Pro Tools',
                    'Logic Pro',
                    'Ableton Live',
                    'Cubase',
                    'Studio One'
                ], $this->faker->numberBetween(1, 3)),
            ],
            'master_ownership_percentage' => $this->faker->randomFloat(2, 50, 100),
            'publishing_ownership_percentage' => $this->faker->randomFloat(2, 0, 50),
            'rights_holders' => [
                [
                    'name' => $this->faker->name(),
                    'role' => 'Artist',
                    'percentage' => $this->faker->randomFloat(2, 70, 100),
                ],
                [
                    'name' => $this->faker->company(),
                    'role' => 'Label',
                    'percentage' => $this->faker->randomFloat(2, 0, 30),
                ],
            ],
            'publishing_splits' => [
                [
                    'contributor' => $this->faker->name(),
                    'role' => 'Songwriter',
                    'percentage' => $this->faker->randomFloat(2, 50, 100),
                ],
                [
                    'contributor' => $this->faker->name(),
                    'role' => 'Composer',
                    'percentage' => $this->faker->randomFloat(2, 0, 50),
                ],
            ],
            'status' => $this->faker->randomElement(['pending', 'registered', 'disputed', 'cancelled']),
            'registered_at' => $this->faker->optional(0.7)->dateTimeBetween('-1 year', 'now'),
            'registration_authority' => 'Uganda Registration Authority',
            'registration_reference' => $this->faker->optional(0.7)->bothify('URA-####-????'),
            'international_registration' => $this->faker->boolean(30),
            'international_territories' => $this->faker->optional(0.3)->randomElements([
                'Kenya',
                'Tanzania',
                'Rwanda',
                'South Sudan',
                'Congo',
                'Global'
            ], $this->faker->numberBetween(1, 4)),
            'international_registered_at' => $this->faker->optional(0.2)->dateTimeBetween('-1 year', 'now'),
            'work_title' => $this->faker->sentence(3),
            'alternative_titles' => $this->faker->optional(0.3)->sentences(2),
            'version_info' => $this->faker->optional(0.2)->randomElement([
                'Original Version',
                'Radio Edit',
                'Extended Mix',
                'Acoustic Version',
                'Remix'
            ]),
            'duration_seconds' => $this->faker->numberBetween(120, 600),
            'genres' => $this->faker->randomElements([
                'Afrobeat',
                'Pop',
                'Hip Hop',
                'R&B',
                'Gospel',
                'Traditional',
                'Folk',
                'Electronic'
            ], $this->faker->numberBetween(1, 3)),
            'primary_language' => $this->faker->randomElement([
                'English',
                'Luganda',
                'Swahili',
                'Runyoro',
                'Luo',
                'Ateso'
            ]),
            'featured_artists' => $this->faker->optional(0.4)->randomElements([
                $this->faker->name(),
                $this->faker->name(),
                $this->faker->name()
            ], $this->faker->numberBetween(1, 2)),
            'copyright_owner' => $this->faker->company(),
            'copyright_year' => $this->faker->numberBetween(2015, date('Y')),
            'phonogram_producer' => $this->faker->company(),
            'phonogram_year' => $this->faker->numberBetween(2015, date('Y')),
            'cleared_for_distribution' => $this->faker->boolean(60),
            'distribution_cleared_at' => $this->faker->optional(0.6)->dateTimeBetween('-6 months', 'now'),
            'distribution_restrictions' => $this->faker->optional(0.2)->randomElements([
                'No explicit content platforms',
                'Limited to streaming only',
                'Exclude specific territories'
            ], $this->faker->numberBetween(1, 2)),
            'territorial_restrictions' => $this->faker->optional(0.1)->randomElements([
                'China',
                'Iran',
                'North Korea',
                'Syria'
            ], $this->faker->numberBetween(1, 2)),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'registered_at' => null,
            'registration_reference' => null,
            'cleared_for_distribution' => false,
            'distribution_cleared_at' => null,
        ]);
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'registered',
            'registered_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'registration_reference' => 'URA-' . $this->faker->numerify('####') . '-' . $this->faker->lexify('????'),
        ]);
    }

    public function clearedForDistribution(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'registered',
            'registered_at' => $this->faker->dateTimeBetween('-1 year', '-1 month'),
            'registration_reference' => 'URA-' . $this->faker->numerify('####') . '-' . $this->faker->lexify('????'),
            'cleared_for_distribution' => true,
            'distribution_cleared_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    public function international(): static
    {
        return $this->state(fn (array $attributes) => [
            'international_registration' => true,
            'international_territories' => $this->faker->randomElements([
                'Kenya',
                'Tanzania',
                'Rwanda',
                'South Sudan',
                'Congo',
                'Global'
            ], $this->faker->numberBetween(2, 5)),
            'international_registered_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ]);
    }

    public function withRestrictions(): static
    {
        return $this->state(fn (array $attributes) => [
            'territorial_restrictions' => $this->faker->randomElements([
                'China',
                'Iran',
                'North Korea',
                'Syria',
                'Russia'
            ], $this->faker->numberBetween(1, 3)),
            'distribution_restrictions' => $this->faker->randomElements([
                'No explicit content platforms',
                'Streaming only',
                'No social media platforms',
                'Limited territory release'
            ], $this->faker->numberBetween(1, 2)),
        ]);
    }

    public function ugandanCode(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'UG',
            'registrant_name' => $this->faker->company() . ' Uganda',
            'recording_location' => $this->faker->randomElement([
                'Kampala, Uganda',
                'Entebbe, Uganda',
                'Jinja, Uganda',
                'Mbarara, Uganda',
                'Gulu, Uganda'
            ]),
        ]);
    }

    public function currentYear(): static
    {
        $currentYear = date('Y');
        $yearCode = substr($currentYear, 2, 2);

        return $this->state(fn (array $attributes) => [
            'year_code' => $yearCode,
            'copyright_year' => $currentYear,
            'phonogram_year' => $currentYear,
            'recording_date' => $this->faker->dateTimeBetween('January 1, ' . $currentYear, 'now'),
        ]);
    }
}