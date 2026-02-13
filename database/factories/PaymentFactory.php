<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        // Define valid payment method/provider combinations
        // Method = what type (mobile_money, card, bank_transfer)
        // Provider = specific service (mtn_mobile_money, stripe, etc)
        $methodProviderCombos = [
            ['method' => 'mobile_money', 'provider' => 'mtn_mobile_money'],
            ['method' => 'mobile_money', 'provider' => 'airtel_money'],
            ['method' => 'card', 'provider' => 'stripe'],
            ['method' => 'card', 'provider' => 'flutterwave'],
            ['method' => 'bank_transfer', 'provider' => 'bank'],
            ['method' => 'platform_credits', 'provider' => 'internal'],
        ];
        
        // Randomly select a valid combination
        $combo = $this->faker->randomElement($methodProviderCombos);
        
        // Ensure payment_method and payment_provider are correctly separated
        $paymentMethod = $combo['method'];
        $paymentProvider = $combo['provider'];
        
        // Determine payable type (what the payment is for)
        $payableTypes = [
            'App\\Models\\UserSubscription',
            'App\\Models\\Song',
            'App\\Models\\Album',
            'App\\Models\\Artist',
            'App\\Models\\EventTicket',
            'App\\Models\\CreditPurchase',
        ];
        
        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'payable_type' => $this->faker->randomElement($payableTypes),
            'payable_id' => 1, // Will be overridden in tests
            'transaction_id' => 'TXN_' . strtoupper($this->faker->unique()->bothify('??###??###')),
            'amount' => $this->faker->randomFloat(2, 1000, 100000),
            'currency' => 'UGX',
            'payment_method' => $paymentMethod,
            'payment_provider' => $paymentProvider,
            'status' => 'pending',
            'payment_details' => [
                'ip_address' => $this->faker->ipv4(),
                'user_agent' => $this->faker->userAgent(),
                'device_type' => $this->faker->randomElement(['mobile', 'desktop', 'tablet']),
            ],
            'provider_response' => [
                'status' => 'success',
                'code' => '200',
                'message' => 'Transaction completed successfully',
            ],
            'completed_at' => $this->faker->optional(0.7)->dateTimeBetween('-30 days', 'now'),
            'failure_reason' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'failure_reason' => $this->faker->randomElement(['INSUFFICIENT_FUNDS', 'INVALID_CARD', 'NETWORK_ERROR', 'TRANSACTION_DECLINED']),
            'provider_response' => [
                'status' => 'error',
                'error_code' => $this->faker->randomElement(['INSUFFICIENT_FUNDS', 'INVALID_CARD', 'NETWORK_ERROR']),
                'error_message' => $this->faker->sentence(),
            ],
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'refunded',
            'payment_details' => array_merge($attributes['payment_details'] ?? [], [
                'refund_reason' => $this->faker->randomElement([
                    'Customer request',
                    'Duplicate payment',
                    'Technical error',
                    'Subscription cancelled'
                ]),
                'refund_amount' => $attributes['amount'] ?? 0,
            ]),
        ]);
    }

    public function mobileMoneyMTN(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'mobile_money',
            'payment_provider' => 'mtn_mobile_money',
            'payment_details' => array_merge($attributes['payment_details'] ?? [], [
                'network' => 'MTN',
                'phone_number' => '+256' . $this->faker->numerify('70#######'),
            ]),
        ]);
    }

    public function mobileMoneyAirtel(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'mobile_money',
            'payment_provider' => 'airtel_money',
            'payment_details' => array_merge($attributes['payment_details'] ?? [], [
                'network' => 'Airtel',
                'phone_number' => '+256' . $this->faker->numerify('75#######'),
            ]),
        ]);
    }

    public function stripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'card',
            'payment_provider' => 'stripe',
            'currency' => 'USD',
            'payment_details' => array_merge($attributes['payment_details'] ?? [], [
                'stripe_payment_intent' => 'pi_' . $this->faker->bothify('????????????????'),
                'card_last_four' => $this->faker->numerify('####'),
                'card_brand' => $this->faker->randomElement(['visa', 'mastercard', 'amex']),
            ]),
        ]);
    }
}