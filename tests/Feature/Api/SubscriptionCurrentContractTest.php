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
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_subscription', true)
            ->assertJsonPath('data.plan', 'premium')
            ->assertJsonPath('data.ad_free', true)
            ->assertJsonPath('data.offline_access', true)
            ->assertJsonPath('data.limits.downloads_per_day', 0)
            ->assertJsonPath('data.limits.audio_quality_kbps', 320)
            ->assertJsonPath('data.limits.uploads_per_month', 12);
    }
}
