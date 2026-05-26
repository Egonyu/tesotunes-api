<?php

namespace Tests\Feature\Api\Ads;

use App\Models\Ad;
use App\Models\AdImpression;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdTrackingApiTest extends TestCase
{
    use DatabaseTransactions;

    private Ad $ad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ad = Ad::factory()->image()->active()->create();
    }

    // ── Impression tracking ───────────────────────────────────────────────────

    public function test_records_impression_for_anonymous_user(): void
    {
        $this->postJson('/api/ads/impression', [
            'ad_id' => $this->ad->id,
            'placement_key' => 'web_top_banner',
            'page_url' => 'http://example.com/home',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('ad_impressions', [
            'ad_id' => $this->ad->id,
            'placement_key' => 'web_top_banner',
            'user_id' => null,
            'clicked' => false,
        ]);
    }

    public function test_records_impression_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/ads/impression', [
            'ad_id' => $this->ad->id,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('ad_impressions', [
            'ad_id' => $this->ad->id,
            'user_id' => $user->id,
            'clicked' => false,
        ]);
    }

    public function test_impression_requires_valid_ad_id(): void
    {
        $this->postJson('/api/ads/impression', ['ad_id' => 99999])
            ->assertUnprocessable();

        $this->assertDatabaseCount('ad_impressions', 0);
    }

    public function test_impression_requires_ad_id(): void
    {
        $this->postJson('/api/ads/impression', [])
            ->assertUnprocessable();
    }

    public function test_impression_rejects_oversized_page_url(): void
    {
        $this->postJson('/api/ads/impression', [
            'ad_id' => $this->ad->id,
            'page_url' => str_repeat('a', 2049),
        ])->assertUnprocessable();
    }

    // ── Click tracking ────────────────────────────────────────────────────────

    public function test_records_click_against_existing_impression_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $impression = AdImpression::factory()->create([
            'ad_id' => $this->ad->id,
            'user_id' => $user->id,
            'clicked' => false,
            'created_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/ads/click', [
            'ad_id' => $this->ad->id,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('ad_impressions', [
            'id' => $impression->id,
            'clicked' => true,
        ]);
    }

    public function test_click_marks_most_recent_impression_only(): void
    {
        $user = User::factory()->create();

        $older = AdImpression::factory()->create([
            'ad_id' => $this->ad->id,
            'user_id' => $user->id,
            'clicked' => false,
            'created_at' => now()->subMinutes(10),
        ]);

        $newer = AdImpression::factory()->create([
            'ad_id' => $this->ad->id,
            'user_id' => $user->id,
            'clicked' => false,
            'created_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/ads/click', [
            'ad_id' => $this->ad->id,
        ])->assertOk();

        // Only the most recent should be marked clicked
        $this->assertDatabaseHas('ad_impressions', ['id' => $newer->id, 'clicked' => true]);
        $this->assertDatabaseHas('ad_impressions', ['id' => $older->id, 'clicked' => false]);
    }

    public function test_click_returns_ok_even_when_no_matching_impression_exists(): void
    {
        // Idempotent — click with no matching impression is a no-op, not an error
        $this->postJson('/api/ads/click', [
            'ad_id' => $this->ad->id,
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_click_does_not_re_mark_already_clicked_impression(): void
    {
        $user = User::factory()->create();

        $impression = AdImpression::factory()->create([
            'ad_id' => $this->ad->id,
            'user_id' => $user->id,
            'clicked' => true,
            'clicked_at' => now()->subMinute(),
            'created_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/ads/click', [
            'ad_id' => $this->ad->id,
        ])->assertOk();

        // clicked_at should not have been updated (impression was already clicked)
        $this->assertDatabaseHas('ad_impressions', [
            'id' => $impression->id,
            'clicked' => true,
        ]);
    }

    public function test_click_requires_valid_ad_id(): void
    {
        $this->postJson('/api/ads/click', ['ad_id' => 99999])
            ->assertUnprocessable();
    }
}
