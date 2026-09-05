<?php

namespace Tests\Feature\Credits;

use App\Models\CreditRate;
use App\Models\ReferralMilestone;
use App\Models\ReferralMilestoneClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The member referral programme.
 *
 * The four screens under /referrals shipped calling endpoints that did not
 * exist, so every request 404'd — signup rewards were paying out and no
 * member could see it. These cover the programme end to end, with particular
 * attention to the milestone claim, which must never pay twice.
 */
class ReferralProgramTest extends TestCase
{
    use DatabaseTransactions;

    private function milestone(int $required, int $reward = 1000): ReferralMilestone
    {
        return ReferralMilestone::create([
            'key' => 'test_'.$required.'_'.uniqid(),
            'name' => "Bring {$required}",
            'referrals_required' => $required,
            'reward_type' => ReferralMilestone::REWARD_CREDITS,
            'reward_value' => $reward,
            'badge_name' => 'Tester',
            'badge_icon' => '🎯',
            'badge_tier' => ReferralMilestone::TIER_BRONZE,
            'is_active' => true,
        ]);
    }

    private function referrerWith(int $referrals): User
    {
        $referrer = User::factory()->create(['referral_code' => 'CODE'.uniqid()]);

        User::factory()->count($referrals)->create(['referrer_id' => $referrer->id]);

        return $referrer;
    }

    public function test_the_dashboard_reports_the_code_link_and_referral_count(): void
    {
        $referrer = $this->referrerWith(2);

        $response = $this->actingAs($referrer)->getJson('/api/referrals/dashboard')->assertOk();

        $this->assertSame($referrer->referral_code, $response->json('data.referral_code'));
        $this->assertStringContainsString($referrer->referral_code, $response->json('data.referral_link'));
        $this->assertSame(2, $response->json('data.stats.total'));
        $this->assertCount(2, $response->json('data.recent_referrals'));
    }

    public function test_the_referral_link_points_at_the_frontend_not_the_api(): void
    {
        $referrer = $this->referrerWith(0);

        $link = $this->actingAs($referrer)->getJson('/api/referrals/code')->json('data.referral_link');

        $this->assertStringNotContainsString('/api/', $link);
    }

    public function test_history_paginates_and_never_exposes_a_referred_person_s_email(): void
    {
        $referrer = $this->referrerWith(3);

        $response = $this->actingAs($referrer)
            ->getJson('/api/referrals/history?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data.referrals'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.pagination.last_page'));

        $this->assertArrayNotHasKey(
            'email',
            $response->json('data.referrals.0.user'),
            'A referrer must not be handed the email address of someone who used their link.'
        );
    }

    public function test_a_milestone_becomes_claimable_only_once_it_is_reached(): void
    {
        $milestone = $this->milestone(required: 5);
        $referrer = $this->referrerWith(3);

        $locked = collect($this->actingAs($referrer)->getJson('/api/referrals/rewards')->json('data.milestones'))
            ->firstWhere('id', $milestone->id);

        $this->assertSame('locked', $locked['status']);
        $this->assertSame(60, $locked['progress']);

        $this->actingAs($referrer)
            ->postJson("/api/referrals/rewards/{$milestone->id}/claim")
            ->assertStatus(422);
    }

    public function test_claiming_a_reached_milestone_pays_the_credits(): void
    {
        $milestone = $this->milestone(required: 2, reward: 1500);
        $referrer = $this->referrerWith(2);
        $referrer->ensureCreditWallet();

        $this->actingAs($referrer)
            ->postJson("/api/referrals/rewards/{$milestone->id}/claim")
            ->assertOk();

        $this->assertEquals(1500, (float) $referrer->fresh()->creditWallet->balance);
        $this->assertSame(1, ReferralMilestoneClaim::where('user_id', $referrer->id)->count());
    }

    public function test_a_milestone_cannot_be_claimed_twice(): void
    {
        $milestone = $this->milestone(required: 1, reward: 900);
        $referrer = $this->referrerWith(1);
        $referrer->ensureCreditWallet();

        $this->actingAs($referrer)->postJson("/api/referrals/rewards/{$milestone->id}/claim")->assertOk();
        $this->actingAs($referrer)->postJson("/api/referrals/rewards/{$milestone->id}/claim")->assertStatus(422);

        $this->assertEquals(
            900,
            (float) $referrer->fresh()->creditWallet->balance,
            'A second claim must not pay again.'
        );
        $this->assertSame(1, ReferralMilestoneClaim::where('user_id', $referrer->id)->count());
    }

    public function test_the_leaderboard_ranks_by_referrals_and_places_the_viewer(): void
    {
        $top = $this->referrerWith(4);
        $second = $this->referrerWith(2);

        $response = $this->actingAs($second)->getJson('/api/referrals/leaderboard')->assertOk();

        $board = collect($response->json('data.leaderboard'));
        $topRow = $board->firstWhere('user_id', $top->id);
        $secondRow = $board->firstWhere('user_id', $second->id);

        $this->assertNotNull($topRow);
        $this->assertLessThan($secondRow['rank'], $topRow['rank']);
        $this->assertSame(2, $response->json('data.user_position.referrals'));
    }

    public function test_someone_who_has_referred_nobody_has_no_leaderboard_position(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/referrals/leaderboard')->assertOk();

        $this->assertNull($response->json('data.user_position'));
    }

    public function test_a_code_can_be_checked_before_signing_up(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'TESO123']);

        $this->getJson('/api/referrals/validate/TESO123')
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.referrer_name', $referrer->name);

        $this->getJson('/api/referrals/validate/NOPE999')
            ->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_the_signup_reward_is_attributable_to_the_person_who_joined(): void
    {
        CreditRate::updateOrCreate(
            ['activity_type' => CreditRate::REFERRAL_SIGNUP],
            ['credits_per_action' => 500, 'is_active' => true, 'display_name' => 'Referral signup'],
        );

        $referrer = User::factory()->create(['referral_code' => 'ATTRIB1']);
        $referrer->ensureCreditWallet();

        $this->postJson('/api/auth/register', [
            'name' => 'New Person',
            'email' => 'newperson'.uniqid().'@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => 'ATTRIB1',
        ])->assertSuccessful();

        $referred = User::where('referrer_id', $referrer->id)->firstOrFail();

        // The history screen shows what each referral brought in, which needs
        // the payout tied to the account rather than pooled.
        $row = collect($this->actingAs($referrer)->getJson('/api/referrals/history')->json('data.referrals'))
            ->firstWhere('id', (string) $referred->id);

        $this->assertNotNull($row);
        $this->assertSame(500, $row['credits_earned']);
    }

    /**
     * The screen states what the programme pays instead of hardcoding it,
     * because the rate is operator-editable and can sit inside a time window.
     */
    public function test_the_dashboard_states_the_live_reward_rates(): void
    {
        CreditRate::updateOrCreate(
            ['activity_type' => CreditRate::REFERRAL_SIGNUP],
            ['credits_per_action' => 500, 'is_active' => true, 'display_name' => 'Referral signup'],
        );
        CreditRate::updateOrCreate(
            ['activity_type' => CreditRate::REFERRAL_WELCOME],
            ['credits_per_action' => 200, 'is_active' => true, 'display_name' => 'Referral welcome'],
        );

        $response = $this->actingAs($this->referrerWith(0))
            ->getJson('/api/referrals/dashboard')
            ->assertOk();

        $this->assertSame(500, $response->json('data.reward_rates.referrer_credits'));
        $this->assertSame(200, $response->json('data.reward_rates.joiner_credits'));
    }

    public function test_a_rate_outside_its_window_is_reported_as_paying_nothing(): void
    {
        CreditRate::updateOrCreate(
            ['activity_type' => CreditRate::REFERRAL_SIGNUP],
            [
                'credits_per_action' => 500,
                'is_active' => true,
                'display_name' => 'Referral signup',
                'starts_at' => now()->addWeek(),
                'ends_at' => now()->addWeeks(2),
            ],
        );

        $response = $this->actingAs($this->referrerWith(0))
            ->getJson('/api/referrals/dashboard')
            ->assertOk();

        $this->assertSame(0, $response->json('data.reward_rates.referrer_credits'));
    }

    public function test_a_share_can_be_recorded(): void
    {
        $this->actingAs($this->referrerWith(0))
            ->postJson('/api/referrals/share', ['platform' => 'whatsapp'])
            ->assertOk();
    }

    public function test_referral_endpoints_require_authentication(): void
    {
        foreach (['dashboard', 'code', 'history', 'rewards', 'leaderboard'] as $endpoint) {
            $this->getJson("/api/referrals/{$endpoint}")->assertUnauthorized();
        }
    }
}
