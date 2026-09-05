<?php

namespace Tests\Feature\Api;

use App\Enums\Capability;
use App\Models\User;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Store\Models\Store;
use App\Services\Accounts\CapabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A promoter used to have two profiles. Onboarding at /become-promoter wrote
 * promoter_profiles; the studio editor at /artist/promotions/profile wrote a
 * stores.metadata.promoter_profile blob. Someone who used both was editing
 * two different records and seeing whichever the page they opened happened to
 * read. The capabilities doc named this duplication as the thing to remove.
 */
class PromoterProfileConsolidationTest extends TestCase
{
    use DatabaseTransactions;

    private function promoter(): User
    {
        $user = User::factory()->create();
        app(CapabilityService::class)->grant($user, Capability::Promoter);
        Store::factory()->create(['user_id' => $user->id]);

        return $user->fresh();
    }

    public function test_the_studio_editor_writes_the_promoter_profiles_table(): void
    {
        $user = $this->promoter();

        $this->actingAs($user)
            ->putJson('/api/my/promoter-profile', [
                'audience_summary' => 'Teso region, mostly diaspora on TikTok.',
                'response_time_hours' => 6,
                'proof_points' => ['12 campaigns delivered'],
            ])
            ->assertOk();

        $profile = PromoterProfile::where('user_id', $user->id)->first();

        $this->assertNotNull($profile, 'The editor must persist to promoter_profiles.');
        $this->assertSame('Teso region, mostly diaspora on TikTok.', $profile->audience_summary);
        $this->assertSame(6, (int) $profile->response_time_hours);
        $this->assertSame(['12 campaigns delivered'], $profile->proof_points);
    }

    public function test_both_surfaces_read_the_same_record(): void
    {
        $user = $this->promoter();

        $this->actingAs($user)->putJson('/api/my/promoter-profile', [
            'audience_summary' => 'One record, two doors.',
        ])->assertOk();

        // The V2 onboarding surface must see what the studio editor wrote.
        $this->actingAs($user)
            ->getJson('/api/promoters/me/profile')
            ->assertOk()
            ->assertJsonPath('data.audience_summary', 'One record, two doors.');

        // And the studio editor must read it back.
        $this->actingAs($user)
            ->getJson('/api/my/promoter-profile')
            ->assertOk()
            ->assertJsonPath('data.audience_summary', 'One record, two doors.');
    }

    public function test_a_seller_who_never_onboarded_gets_one_profile_not_a_second_shape(): void
    {
        $user = $this->promoter();
        $this->assertNull(PromoterProfile::where('user_id', $user->id)->first());

        $this->actingAs($user)
            ->putJson('/api/my/promoter-profile', ['audience_summary' => 'First time here.'])
            ->assertOk();

        $this->assertSame(
            1,
            PromoterProfile::where('user_id', $user->id)->count(),
            'Editing must create exactly one profile, through the same onboarding path everyone else uses.'
        );
    }

    /**
     * The validation rule for a portfolio item's platform called
     * promotionPlatforms(), which was never defined — so any profile update
     * carrying portfolio items died with "Call to undefined method".
     */
    public function test_a_profile_update_with_portfolio_items_does_not_fatal(): void
    {
        $user = $this->promoter();

        $this->actingAs($user)
            ->putJson('/api/my/promoter-profile', [
                'portfolio_items' => [
                    ['title' => 'Album launch push', 'platform' => 'tiktok', 'summary' => 'Three-day run'],
                ],
            ])
            ->assertOk();

        $profile = PromoterProfile::where('user_id', $user->id)->first();
        $this->assertSame('Album launch push', $profile->portfolio_items[0]['title'] ?? null);
    }

    public function test_an_unknown_portfolio_platform_is_rejected(): void
    {
        $user = $this->promoter();

        $this->actingAs($user)
            ->putJson('/api/my/promoter-profile', [
                'portfolio_items' => [
                    ['title' => 'Somewhere else', 'platform' => 'myspace'],
                ],
            ])
            ->assertStatus(422);
    }
}
