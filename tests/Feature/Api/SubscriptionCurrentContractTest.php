<?php

namespace Tests\Feature\Api;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCurrentContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_subscription_exposes_entitlements_for_feature_locking(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->premium()->create([
            'slug' => 'premium',
            'name' => 'Premium',
            'max_downloads_per_day' => null,
            'downloads_per_day' => null,
            'max_uploads_per_month' => 12,
            'max_audio_quality_kbps' => 320,
            'has_ads' => false,
            'offline_mode' => true,
            'allows_offline' => true,
            'ad_free' => true,
            'entitlements' => [
                'streaming.ad_free' => true,
                'streaming.audio_quality_kbps' => 256,
                'streaming.downloads_per_day' => 20,
                'streaming.offline' => true,
                'creator.uploads_per_month' => 7,
                'finance.withdrawal_minimum_ugx' => 10000,
            ],
        ]);

        UserSubscription::factory()->active()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/user/subscription')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_subscription', true)
            ->assertJsonPath('data.plan', 'premium')
            ->assertJsonPath('data.ad_free', true)
            ->assertJsonPath('data.offline_access', true)
            ->assertJsonPath('data.limits.downloads_per_day', 20)
            ->assertJsonPath('data.limits.audio_quality_kbps', 256)
            ->assertJsonPath('data.limits.uploads_per_month', 7);

        $this->assertSame(10000, $response->json('data.entitlements')['finance.withdrawal_minimum_ugx']);

        $freshUser = $user->fresh();
        $this->assertSame(256, $freshUser->getMaxAudioQuality());
        $this->assertSame(7, $freshUser->getMonthlyUploadLimit());
        $this->assertTrue($freshUser->hasSubscriptionEntitlement('streaming.offline'));
    }

    public function test_a_plan_allowing_no_downloads_is_not_reported_as_unlimited(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'slug' => 'no-downloads',
            'max_downloads_per_day' => 0,
            'downloads_per_day' => 0,
        ]);

        UserSubscription::factory()->active()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

        $this->actingAs($user)
            ->getJson('/api/user/subscription')
            ->assertOk()
            ->assertJsonPath('data.limits.downloads_per_day', 0);

        $this->assertFalse($user->fresh()->canDownload());
    }
}
