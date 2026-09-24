<?php

namespace Tests\Feature\Payments;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Payment\MobileMoneyService;
use App\Services\Payment\ZengaPayService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutSettingsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_payouts_use_payment_settings_even_when_a_plan_has_wallet_terms(): void
    {
        Setting::set('payments_minimum_payout_ugx', 42000, Setting::TYPE_INTEGER, Setting::GROUP_PAYMENTS);
        Setting::set('payments_transaction_fee_percentage', 1.75, Setting::TYPE_FLOAT, Setting::GROUP_PAYMENTS);

        $service = new class($this->mock(MobileMoneyService::class), $this->mock(ZengaPayService::class)) extends PayoutService
        {
            public function minimumFor(?User $user): int
            {
                return $this->minAmount($user);
            }

            public function feeFor(string $method, ?User $user): float
            {
                return $this->feeRate($method, $user);
            }
        };

        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'entitlements' => [
                'finance.withdrawal_minimum_ugx' => 5000,
                'finance.withdrawal_fee_percent' => 0.5,
            ],
        ]);
        UserSubscription::factory()->active()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
        ]);

        $this->assertSame(42000, $service->minimumFor($user));
        $this->assertSame(1.75, $service->feeFor('mobile_money', $user));
    }
}
