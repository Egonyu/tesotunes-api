<?php

namespace Database\Factories;

use App\Models\UserSubscription;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserSubscriptionFactory extends Factory
{
    protected $model = UserSubscription::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('-6 months', 'now');
        $plan = SubscriptionPlan::factory()->create();
        $endDate = (clone $startDate)->modify("+{$plan->duration_days} days");

        return [
            'user_id' => User::factory(),
            'subscription_plan_id' => $plan->id,
            'payment_id' => Payment::factory(),
            'started_at' => $startDate,
            'expires_at' => $endDate,
            'status' => $this->faker->randomElement(['active', 'expired', 'cancelled', 'paused']),
            'auto_renew' => $this->faker->boolean(70),
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'paused_at' => null,
            'pause_reason' => null,
            'resumed_at' => null,
            'extended_at' => null,
            'extension_reason' => null,
            'metadata' => [
                'source' => $this->faker->randomElement(['web', 'mobile_app', 'api']),
                'referral_code' => $this->faker->optional(0.3)->bothify('REF-????-####'),
                'trial_used' => $this->faker->boolean(40),
                'upgrade_from' => $this->faker->optional(0.2)->randomElement(['basic', 'premium']),
            ],
            'created_at' => $startDate,
            'updated_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'started_at' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'expires_at' => $this->faker->dateTimeBetween('now', '+3 months'),
            'auto_renew' => true,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'paused_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'started_at' => $this->faker->dateTimeBetween('-6 months', '-2 months'),
            'expires_at' => $this->faker->dateTimeBetween('-2 months', '-1 day'),
            'auto_renew' => false,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'auto_renew' => false,
            'cancelled_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'cancellation_reason' => $this->faker->randomElement([
                'Too expensive',
                'Not using features',
                'Found alternative',
                'Technical issues',
                'Customer service issues',
                'Payment failed',
                'User request'
            ]),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
            'paused_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'pause_reason' => $this->faker->randomElement([
                'Temporary financial difficulty',
                'Traveling',
                'Not using service',
                'Technical issues',
                'User request'
            ]),
        ]);
    }

    public function withTrial(): static
    {
        $trialStart = $this->faker->dateTimeBetween('-1 month', 'now');
        $trialEnd = (clone $trialStart)->modify('+14 days');

        return $this->state(fn (array $attributes) => [
            'started_at' => $trialStart,
            'expires_at' => $trialEnd,
            'status' => 'active',
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'trial_used' => true,
                'trial_start' => $trialStart->format('Y-m-d'),
                'trial_end' => $trialEnd->format('Y-m-d'),
                'trial_days' => 14,
            ]),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'expires_at' => $this->faker->dateTimeBetween('now', '+7 days'),
            'auto_renew' => $this->faker->boolean(50),
        ]);
    }

    public function recentlyUpgraded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'upgrade_from' => $this->faker->randomElement(['basic', 'premium']),
                'upgrade_date' => $this->faker->dateTimeBetween('-7 days', 'now')->format('Y-m-d'),
            ]),
        ]);
    }

    public function extended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'extended_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'extension_reason' => $this->faker->randomElement([
                'Promotional extension',
                'Service disruption compensation',
                'Customer retention',
                'Billing error correction',
                'Special offer'
            ]),
            'expires_at' => $this->faker->dateTimeBetween('+1 month', '+3 months'),
        ]);
    }

    public function autoRenewEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'auto_renew' => true,
            'status' => 'active',
        ]);
    }

    public function autoRenewDisabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'auto_renew' => false,
        ]);
    }

    public function withReferral(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'referral_code' => 'REF-' . strtoupper($this->faker->bothify('????-####')),
                'referred_by' => User::factory()->create()->id,
                'referral_credit_applied' => $this->faker->randomFloat(2, 5, 50),
            ]),
        ]);
    }

    public function gracePeriod(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'expires_at' => $this->faker->dateTimeBetween('-7 days', '-1 day'),
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'grace_period_active' => true,
                'grace_period_ends' => $this->faker->dateTimeBetween('now', '+3 days')->format('Y-m-d'),
                'payment_retry_count' => $this->faker->numberBetween(1, 3),
            ]),
        ]);
    }

    public function corporateAccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'account_type' => 'corporate',
                'company_name' => $this->faker->company(),
                'billing_contact' => $this->faker->email(),
                'seats_purchased' => $this->faker->numberBetween(5, 100),
                'seats_used' => $this->faker->numberBetween(1, $attributes['metadata']['seats_purchased'] ?? 50),
            ]),
        ]);
    }

    public function familyPlan(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'plan_type' => 'family',
                'family_members' => $this->faker->numberBetween(2, 6),
                'primary_account' => true,
                'shared_library' => true,
            ]),
        ]);
    }

    public function studentDiscount(): static
    {
        return $this->state(function (array $attributes) {
            $plan = SubscriptionPlan::find($attributes['subscription_plan_id']);
            $discountedPrice = $plan ? $plan->price_local * 0.5 : 20000; // 50% student discount

            return [
                'metadata' => array_merge($attributes['metadata'] ?? [], [
                    'student_verified' => true,
                    'student_email' => $this->faker->email(),
                    'university' => $this->faker->randomElement([
                        'Makerere University',
                        'Kyambogo University',
                        'Mbarara University',
                        'Gulu University',
                        'Uganda Christian University'
                    ]),
                    'discount_percentage' => 50,
                    'original_price' => $plan ? $plan->price_local : 40000,
                    'discounted_price' => $discountedPrice,
                ]),
            ];
        });
    }
}