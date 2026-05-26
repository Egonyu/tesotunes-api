<?php

namespace Tests\Feature\Api\Ads;

use App\Models\Ad;
use App\Models\AdImpression;
use App\Models\AdPlacementAssignment;
use App\Models\AdPlacementConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdServingApiTest extends TestCase
{
    use DatabaseTransactions;

    private AdPlacementConfig $zone;

    protected function setUp(): void
    {
        parent::setUp();

        // Must use a key from AdPlacement enum — controller validates against it.
        $this->zone = AdPlacementConfig::create([
            'placement_key' => 'web_top_banner',
            'label' => 'Test Zone',
            'device_type' => 'all',
            'allowed_formats' => ['banner_728x90'],
            'is_enabled' => true,
            'target_tiers' => ['free'],
            'frequency_cap_per_day' => 5,
            'max_ads_per_page' => 1,
        ]);
    }

    private function assignAd(Ad $ad, int $weight = 10, int $priority = 5): void
    {
        AdPlacementAssignment::create([
            'ad_id' => $ad->id,
            'placement_key' => $this->zone->placement_key,
            'priority' => $priority,
            'weight' => $weight,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    public function test_returns_null_when_zone_is_disabled(): void
    {
        $this->zone->update(['is_enabled' => false]);

        $ad = Ad::factory()->image()->active()->create(['target_tiers' => null, 'target_devices' => null]);
        $this->assignAd($ad);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_returns_null_when_placement_key_is_invalid(): void
    {
        $this->getJson('/api/ads?placement=does_not_exist')
            ->assertUnprocessable();
    }

    public function test_returns_ad_for_free_user_when_zone_targets_free(): void
    {
        $ad = Ad::factory()->image()->active()->create([
            'target_tiers' => null,
            'target_devices' => null,
        ]);
        $this->assignAd($ad);

        $response = $this->getJson('/api/ads?placement=web_top_banner');

        $response->assertOk()
            ->assertJsonPath('data.id', $ad->id)
            ->assertJsonPath('data.type', 'image');
    }

    public function test_returns_null_when_zone_tier_gate_excludes_user(): void
    {
        // Zone targets only premium, but user is free (unauthenticated = 'free')
        $this->zone->update(['target_tiers' => ['premium']]);

        $ad = Ad::factory()->image()->active()->create(['target_tiers' => null, 'target_devices' => null]);
        $this->assignAd($ad);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_respects_device_targeting_on_ad(): void
    {
        $desktopAd = Ad::factory()->image()->active()->create([
            'target_devices' => ['desktop'],
            'target_tiers' => null,
        ]);
        $this->assignAd($desktopAd);

        // Mobile request should not receive a desktop-only ad
        $this->getJson('/api/ads?placement=web_top_banner&device=mobile')
            ->assertOk()
            ->assertJsonPath('data', null);

        // Desktop request should receive it
        $this->getJson('/api/ads?placement=web_top_banner&device=desktop')
            ->assertOk()
            ->assertJsonPath('data.id', $desktopAd->id);
    }

    public function test_respects_tier_targeting_on_ad(): void
    {
        // Ad only targets premium, but all requests resolve to 'free' here
        $premiumAd = Ad::factory()->image()->active()->create([
            'target_tiers' => ['premium'],
            'target_devices' => null,
        ]);
        $this->assignAd($premiumAd);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_respects_country_targeting_on_ad(): void
    {
        $ugAd = Ad::factory()->image()->active()->ugandaOnly()->create([
            'target_tiers' => null,
            'target_devices' => null,
        ]);
        $this->assignAd($ugAd);

        // No country match → nothing served
        $this->getJson('/api/ads?placement=web_top_banner&country=KE')
            ->assertOk()
            ->assertJsonPath('data', null);

        // Correct country → ad served
        $this->getJson('/api/ads?placement=web_top_banner&country=UG')
            ->assertOk()
            ->assertJsonPath('data.id', $ugAd->id);
    }

    public function test_does_not_serve_inactive_ad(): void
    {
        $ad = Ad::factory()->image()->inactive()->create([
            'target_tiers' => null,
            'target_devices' => null,
        ]);
        $this->assignAd($ad);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_does_not_serve_expired_ad(): void
    {
        $ad = Ad::factory()->image()->create([
            'is_active' => true,
            'ends_at' => now()->subHour(),
            'target_tiers' => null,
            'target_devices' => null,
        ]);
        $this->assignAd($ad);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_does_not_serve_future_scheduled_ad(): void
    {
        $ad = Ad::factory()->image()->create([
            'is_active' => true,
            'starts_at' => now()->addHour(),
            'target_tiers' => null,
            'target_devices' => null,
        ]);
        $this->assignAd($ad);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_enforces_frequency_cap_for_authenticated_user(): void
    {
        $this->zone->update(['frequency_cap_per_day' => 2]);

        $user = User::factory()->create(['is_active' => true]);
        $ad = Ad::factory()->image()->active()->create(['target_tiers' => null, 'target_devices' => null]);
        $this->assignAd($ad);

        // Seed 2 impressions for this user today (at the cap)
        AdImpression::factory()->count(2)->create([
            'user_id' => $user->id,
            'ad_id' => $ad->id,
            'placement_key' => $this->zone->placement_key,
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_frequency_cap_does_not_apply_to_anonymous_users(): void
    {
        $this->zone->update(['frequency_cap_per_day' => 1]);

        $ad = Ad::factory()->image()->active()->create(['target_tiers' => null, 'target_devices' => null]);
        $this->assignAd($ad);

        // Anonymous users are never capped
        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data.id', $ad->id);
    }

    public function test_does_not_serve_ad_over_total_budget(): void
    {
        $ad = Ad::factory()->image()->active()->create([
            'target_tiers' => null,
            'target_devices' => null,
            'total_budget_ugx' => 100.00,
            'cost_per_impression_ugx' => 50.0000, // 2 impressions = budget exhausted
            'cost_per_click_ugx' => null,
        ]);
        $this->assignAd($ad);

        // 2 impressions × 50 UGX = 100 UGX = budget reached
        AdImpression::factory()->count(2)->create([
            'ad_id' => $ad->id,
            'created_at' => now(),
        ]);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_does_not_serve_ad_over_daily_budget(): void
    {
        $ad = Ad::factory()->image()->active()->create([
            'target_tiers' => null,
            'target_devices' => null,
            'daily_budget_ugx' => 100.00,
            'cost_per_impression_ugx' => 100.0000, // 1 impression = daily budget exhausted
            'cost_per_click_ugx' => null,
        ]);
        $this->assignAd($ad);

        AdImpression::factory()->create([
            'ad_id' => $ad->id,
            'created_at' => now(),
        ]);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_still_serves_ad_with_budget_set_but_no_cost_rate(): void
    {
        // If budget is set but no CPM/CPC, we can't compute spend — pass through
        $ad = Ad::factory()->image()->active()->create([
            'target_tiers' => null,
            'target_devices' => null,
            'total_budget_ugx' => 100.00,
            'cost_per_impression_ugx' => null,
            'cost_per_click_ugx' => null,
        ]);
        $this->assignAd($ad);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data.id', $ad->id);
    }

    public function test_returns_null_when_ads_feature_flag_is_disabled(): void
    {
        $ad = Ad::factory()->image()->active()->create(['target_tiers' => null, 'target_devices' => null]);
        $this->assignAd($ad);

        config(['ads.enabled' => false]);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);

        config(['ads.enabled' => true]);
    }

    public function test_response_includes_all_expected_fields_for_image_ad(): void
    {
        $ad = Ad::factory()->image()->active()->create([
            'target_tiers' => null,
            'target_devices' => null,
        ]);
        $this->assignAd($ad);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'type', 'format',
                    'image_url', 'click_url', 'cta_text',
                    'placement_key',
                ],
            ]);
    }

    public function test_does_not_serve_ad_with_inactive_assignment(): void
    {
        $ad = Ad::factory()->image()->active()->create(['target_tiers' => null, 'target_devices' => null]);

        AdPlacementAssignment::create([
            'ad_id' => $ad->id,
            'placement_key' => $this->zone->placement_key,
            'priority' => 5,
            'weight' => 10,
            'is_active' => false, // inactive assignment
        ]);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_does_not_serve_ad_with_expired_assignment(): void
    {
        $ad = Ad::factory()->image()->active()->create(['target_tiers' => null, 'target_devices' => null]);

        AdPlacementAssignment::create([
            'ad_id' => $ad->id,
            'placement_key' => $this->zone->placement_key,
            'priority' => 5,
            'weight' => 10,
            'is_active' => true,
            'ends_at' => now()->subHour(), // expired
        ]);

        $this->getJson('/api/ads?placement=web_top_banner')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
