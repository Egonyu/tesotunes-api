<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceNotificationStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_subscription_writes_custom_notification_record(): void
    {
        $user = User::factory()->create();
        $plan = $this->createSubscriptionPlan('cancel-plan');
        $subscription = UserSubscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->subDays(5),
            'expires_at' => now()->addDays(30),
            'auto_renew' => true,
            'currency' => 'UGX',
        ]);

        $result = app(PaymentService::class)->cancelSubscription($subscription, 'Requested by user');

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'subscription_cancelled',
            'category' => 'subscription',
            'notifiable_type' => UserSubscription::class,
            'notifiable_id' => $subscription->id,
        ]);
    }

    public function test_extend_subscription_writes_custom_notification_record(): void
    {
        $user = User::factory()->create();
        $plan = $this->createSubscriptionPlan('extend-plan');
        $subscription = UserSubscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->subDays(10),
            'expires_at' => now()->addDays(10),
            'auto_renew' => true,
            'currency' => 'UGX',
        ]);

        app(PaymentService::class)->extendSubscription($subscription, 7, 'Service recovery credit');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'subscription_extended',
            'category' => 'subscription',
            'notifiable_type' => UserSubscription::class,
            'notifiable_id' => $subscription->id,
        ]);
    }

    public function test_process_refund_writes_custom_notification_record(): void
    {
        $user = User::factory()->create();

        $payment = new Payment([
            'user_id' => $user->id,
            'payment_type' => 'wallet_topup',
            'payment_method' => 'zengapay',
            'payment_provider' => 'zengapay',
            'phone_number' => '256700000001',
            'currency' => 'UGX',
            'payment_reference' => 'PAY-REFUND-001',
            'transaction_reference' => 'PAY-REFUND-001',
        ]);
        $payment->forceFill([
            'amount' => 50000,
            'status' => Payment::STATUS_COMPLETED,
            'transaction_id' => 'TXN-REFUND-001',
            'completed_at' => now(),
        ])->save();

        $service = new class extends PaymentService
        {
            protected function processMethodRefund(Payment $payment, float $amount): array
            {
                return [
                    'success' => true,
                    'message' => 'Refund processed',
                ];
            }
        };

        $result = $service->processRefund($payment, 25000, 'Customer requested refund');

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'payment_refunded',
            'category' => 'payments',
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
        ]);
    }

    protected function createSubscriptionPlan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'uuid' => (string) \Str::uuid(),
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => 'Test subscription plan',
            'type' => 'standard',
            'region' => 'EA',
            'tier' => 'basic',
            'price_monthly' => 20000,
            'price_yearly' => 200000,
            'price' => 20000,
            'currency' => 'UGX',
            'interval' => 'month',
            'interval_count' => 1,
            'trial_days' => 0,
            'duration_days' => 30,
            'is_active' => true,
            'is_visible' => true,
            'is_featured' => false,
            'is_trial' => false,
            'is_popular' => false,
            'sort_order' => 1,
        ]);
    }
}
