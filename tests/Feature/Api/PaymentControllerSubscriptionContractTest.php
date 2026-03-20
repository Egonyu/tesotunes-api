<?php

namespace Tests\Feature\Api;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentControllerSubscriptionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_accepts_mtn_momo_alias_and_normalizes_to_mobile_money(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();

        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('processSubscriptionPayment')
            ->once()
            ->withArgs(function ($actualUser, $actualPlan, $paymentMethod, $paymentData) use ($user, $plan) {
                return $actualUser->is($user)
                    && $actualPlan->is($plan)
                    && $paymentMethod === 'mobile_money'
                    && ($paymentData['phone_number'] ?? null) === '256700000111'
                    && ($paymentData['requested_payment_method'] ?? null) === 'mtn_momo';
            })
            ->andReturn([
                'success' => true,
                'payment_id' => 44,
                'payment_status' => 'processing',
                'message' => 'Payment initiated.',
            ]);

        $this->app->instance(PaymentService::class, $paymentService);

        $this->actingAs($user)
            ->postJson('/api/payments/subscription', [
                'plan_id' => $plan->id,
                'payment_method' => 'mtn_momo',
                'phone_number' => '256700000111',
            ])
            ->assertCreated()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.payment_status', 'processing');
    }

    public function test_subscription_accepts_zengapay_alias_and_normalizes_to_mobile_money(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();

        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('processSubscriptionPayment')
            ->once()
            ->withArgs(function ($actualUser, $actualPlan, $paymentMethod, $paymentData) use ($user, $plan) {
                return $actualUser->is($user)
                    && $actualPlan->is($plan)
                    && $paymentMethod === 'mobile_money'
                    && ($paymentData['phone_number'] ?? null) === '256700000222'
                    && ($paymentData['requested_payment_method'] ?? null) === 'zengapay';
            })
            ->andReturn([
                'success' => true,
                'payment_id' => 45,
                'payment_status' => 'processing',
                'message' => 'Payment initiated.',
            ]);

        $this->app->instance(PaymentService::class, $paymentService);

        $this->actingAs($user)
            ->postJson('/api/payments/subscription', [
                'plan_id' => $plan->id,
                'payment_method' => 'zengapay',
                'phone_number' => '256700000222',
            ])
            ->assertCreated()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.payment_id', 45);
    }

    public function test_subscription_rejects_unsupported_credits_method(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/payments/subscription', [
                'plan_id' => $plan->id,
                'payment_method' => 'credits',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);
    }
}
