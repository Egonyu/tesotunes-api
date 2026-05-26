<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArtistProfile>
 */
class ArtistProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ArtistProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stageName = fake()->name();

        return [
            'user_id' => User::factory(),
            'artist_id' => null,
            'stage_name' => $stageName,
            'real_name' => fake()->name(),
            'nin_number' => fake()->optional()->numerify('CM###########'),
            'verified_at' => fake()->boolean(30) ? now() : null,
            'bio' => fake()->paragraphs(2, true),
            'website' => fake()->optional()->url(),
            'social_links' => [
                'instagram' => 'https://instagram.com/'.fake()->userName(),
            ],
            'manager_name' => fake()->optional()->name(),
            'manager_contact' => fake()->optional()->phoneNumber(),
            'genres' => ['Afrobeats'],
            'languages' => ['en', 'ate'],
            'record_label' => fake()->optional()->company(),
            'publishing_company' => fake()->optional()->company(),
            'region' => 'Eastern',
            'district' => fake()->city(),
            'career_stage' => fake()->randomElement(['emerging', 'growing', 'established']),
            'mobile_money_provider' => fake()->randomElement(['mtn', 'airtel']),
            'mobile_money_number' => fake()->numerify('2567########'),
            'bank_name' => fake()->optional()->company().' Bank',
            'bank_account' => fake()->optional()->bankAccountNumber(),
            'payout_method' => fake()->randomElement(['mobile_money', 'bank_transfer']),
            'minimum_payout' => fake()->randomFloat(2, 0, 5000),
            'total_credits_earned' => fake()->randomFloat(2, 0, 50000),
            'total_money_earned' => fake()->randomFloat(2, 0, 500000),
            'money_payout_enabled' => fake()->boolean(30),
            'money_payout_unlocked_at' => fake()->boolean(20) ? now() : null,
            'auto_distribute' => fake()->boolean(30),
            'distribution_preferences' => ['platforms' => ['spotify', 'apple_music']],
            'distribution_fee_percentage' => fake()->randomFloat(2, 0, 20),
            'public_stats' => true,
            'detailed_analytics' => fake()->boolean(50),
            'is_active' => true,
            'last_login_at' => fake()->optional()->dateTimeBetween('-30 days', 'now'),
            'profile_completed' => fake()->boolean(60),
        ];
    }

    /**
     * Indicate that the artist is KYC-verified. Writes through to the related user
     * since the canonical KYC state now lives on users.kyc_status.
     */
    public function verified(): static
    {
        return $this->afterCreating(function (\App\Models\ArtistProfile $profile) {
            $profile->user?->forceFill([
                'kyc_status' => \App\Enums\KycStatus::Verified->value,
                'kyc_verified_at' => now(),
            ])->save();
            $profile->update(['verified_at' => now()]);
        });
    }

    /**
     * Indicate that the artist is pending KYC verification.
     */
    public function pending(): static
    {
        return $this->afterCreating(function (\App\Models\ArtistProfile $profile) {
            $profile->user?->forceFill([
                'kyc_status' => \App\Enums\KycStatus::PendingReview->value,
            ])->save();
            $profile->update(['verified_at' => null]);
        });
    }

    /**
     * Indicate that the artist is a trusted artist.
     */
    public function forArtist(?Artist $artist = null): static
    {
        $artist ??= Artist::factory()->create();

        return $this->state(fn (array $attributes) => [
            'artist_id' => $artist->id,
            'user_id' => $artist->user_id,
            'stage_name' => $artist->stage_name ?? $artist->name,
        ]);
    }
}
