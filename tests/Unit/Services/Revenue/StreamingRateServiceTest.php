<?php

namespace Tests\Unit\Services\Revenue;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Revenue\StreamingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamingRateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_subscription_plan_rate_and_platform_commission(): void
    {
        Setting::set('platform_commissions', [
            'streaming_percent' => 18,
        ], Setting::TYPE_JSON, Setting::GROUP_PAYMENTS);

        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->premium()->create([
            'metadata' => [
                'stream_rate_ugx' => '11.50',
            ],
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $payout = app(StreamingRateService::class)->calculateStreamPayout($user->id);

        $this->assertSame(11.5, $payout['rate_per_stream']);
        $this->assertSame(18.0, $payout['commission_percent']);
        $this->assertSame(2.07, $payout['platform_fee']);
        $this->assertSame(9.43, $payout['net_amount']);
    }

    public function test_it_describes_listener_context_for_plan_backed_streams(): void
    {
        Setting::set('platform_commissions', [
            'streaming_percent' => 18,
        ], Setting::TYPE_JSON, Setting::GROUP_PAYMENTS);

        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->premium()->create([
            'name' => 'Gold Monthly',
            'slug' => 'gold-monthly',
            'tier' => 'premium',
            'metadata' => [
                'stream_rate_ugx' => '11.50',
            ],
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $context = app(StreamingRateService::class)->describeListenerContext($user->id);

        $this->assertTrue($context['has_active_subscription']);
        $this->assertSame($plan->id, $context['listener_plan_id']);
        $this->assertSame('gold-monthly', $context['listener_plan_slug']);
        $this->assertSame('Gold Monthly', $context['listener_plan_name']);
        $this->assertSame('premium', $context['listener_plan_tier']);
        $this->assertSame('11.50', $context['configured_stream_rate_ugx']);
        $this->assertSame('11.50', $context['effective_stream_rate_ugx']);
        $this->assertSame('18.00', $context['streaming_commission_percent']);
        $this->assertSame('2.07', $context['platform_fee_ugx']);
        $this->assertSame('9.43', $context['net_amount_ugx']);
        $this->assertSame('plan_metadata', $context['rate_source']);
    }

    public function test_it_falls_back_to_legacy_free_rate_when_no_subscription_exists(): void
    {
        $user = User::factory()->create();

        $payout = app(StreamingRateService::class)->calculateStreamPayout($user->id, false);

        $this->assertSame(5.0, $payout['rate_per_stream']);
        $this->assertSame(15.0, $payout['commission_percent']);
        $this->assertSame(4.25, $payout['net_amount']);
    }

    public function test_admin_configured_default_rates_override_code_constants(): void
    {
        Setting::set('streaming_default_free_rate_ugx', '7.5', Setting::TYPE_STRING, Setting::GROUP_PAYMENTS);
        Setting::set('streaming_default_premium_rate_ugx', '20', Setting::TYPE_STRING, Setting::GROUP_PAYMENTS);

        $service = app(StreamingRateService::class);

        // No subscription → free default, now sourced from settings.
        $this->assertSame(7.5, $service->resolveRateForUserId(null, false));
        // fallbackPremium → premium default, now sourced from settings.
        $this->assertSame(20.0, $service->resolveRateForUserId(null, true));
    }
}
