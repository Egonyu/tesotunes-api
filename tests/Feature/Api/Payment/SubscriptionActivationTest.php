<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SubscriptionActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_payment_stays_processing_until_confirmation(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'price' => 20000,
            'price_monthly' => 20000,
            'currency' => 'UGX',
            'duration_days' => 30,
        ]);

        $service = Mockery::mock(PaymentService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('processPayment')
            ->once()
            ->andReturn([
                'success' => true,
                'transaction_id' => 'ZG-TXN-001',
                'reference' => 'ZG-REF-001',
                'message' => 'Payment request sent. Please approve on your phone.',
            ]);

        $result = $service->processSubscriptionPayment($user, $plan, 'mobile_money', [
            'phone_number' => '256700000111',
        ]);

        $payment = Payment::findOrFail($result['payment_id']);

        $this->assertTrue($result['success']);
        $this->assertSame('processing', $result['payment_status']);
        $this->assertTrue($result['activation_pending']);
        $this->assertSame('processing', $payment->status);
        $this->assertDatabaseMissing('user_subscriptions', [
            'payment_id' => $payment->id,
        ]);
    }

    public function test_confirmed_subscription_payment_creates_subscription_on_completion(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'currency' => 'UGX',
            'duration_days' => 30,
        ]);

        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'payable_type' => SubscriptionPlan::class,
            'payable_id' => $plan->id,
            'payment_type' => 'subscription',
            'payment_method' => 'zengapay',
            'payment_provider' => 'zengapay',
            'currency' => 'UGX',
            'status' => 'processing',
            'amount' => 20000,
            'payment_reference' => 'PAY-REF-001',
            'transaction_reference' => 'PAY-REF-001',
        ]));

        $payment->markAsCompleted([
            'external_transaction_id' => 'ZG-TXN-999',
            'provider_reference' => 'ZG-EXT-999',
        ]);

        $payment->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertDatabaseHas('user_subscriptions', [
            'payment_id' => $payment->id,
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'payment_method' => 'zengapay',
        ]);
    }
}
