<?php

namespace Tests\Feature\Dashboard;

use App\Models\Artist;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The widened overview payload — one request serves the whole screen, so the
 * client never fans out and no single section can blank the page.
 */
class DashboardPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_carries_every_top_level_section(): void
    {
        $user = User::factory()->create();

        $overview = app(DashboardService::class)->overview($user);

        foreach ([
            'wallet', 'earnings', 'listening', 'profile',
            'next_actions', 'capabilities', 'contributions',
            'artist', 'recent_activity',
        ] as $section) {
            $this->assertArrayHasKey($section, $overview);
        }
    }

    public function test_listening_reports_movement_against_the_previous_window(): void
    {
        $user = User::factory()->create();

        PlayHistory::factory()->count(2)->create([
            'user_id' => $user->id,
            'played_at' => now()->subDays(5),
            'duration_played_seconds' => 180,
        ]);
        PlayHistory::factory()->count(3)->create([
            'user_id' => $user->id,
            'played_at' => now()->subDays(45),
            'duration_played_seconds' => 180,
        ]);

        $listening = app(DashboardService::class)->overview($user)['listening'];

        $this->assertSame(5, $listening['plays_total']);
        $this->assertSame(2, $listening['plays_30d']);
        $this->assertSame(3, $listening['plays_previous_30d']);
        $this->assertSame(6, $listening['minutes_30d']);
        $this->assertNotNull($listening['last_played_at']);
    }

    public function test_listening_is_all_zero_and_null_for_an_account_that_never_played(): void
    {
        $user = User::factory()->create();

        $listening = app(DashboardService::class)->overview($user)['listening'];

        $this->assertSame(0, $listening['plays_total']);
        $this->assertSame(0, $listening['minutes_30d']);
        $this->assertNull($listening['last_played_at']);
    }

    public function test_next_actions_rank_the_profile_steps_the_user_still_owes(): void
    {
        $user = User::factory()->create();

        $actions = app(DashboardService::class)->overview($user)['next_actions'];

        $this->assertNotEmpty($actions);

        foreach ($actions as $action) {
            $this->assertArrayHasKey('key', $action);
            $this->assertArrayHasKey('label', $action);
            $this->assertArrayHasKey('why', $action);
            $this->assertContains($action['importance'], ['high', 'medium', 'low']);
        }

        $importances = array_column($actions, 'importance');
        $ranked = $importances;
        usort($ranked, fn ($a, $b) => ['high' => 0, 'medium' => 1, 'low' => 2][$a]
            <=> ['high' => 0, 'medium' => 1, 'low' => 2][$b]);

        $this->assertSame($ranked, $importances, 'next_actions must arrive ranked.');
    }

    public function test_a_contributor_is_told_what_actually_moves_their_tier(): void
    {
        $user = User::factory()->create();

        ContributorProfile::query()->create([
            'user_id' => $user->id,
            'consented_at' => now(),
            'consent_terms_version' => '2026-06-14',
            'tier' => ContributorProfile::TIER_NOVICE,
            'submissions_total' => 16,
            'submissions_accepted' => 12,
            'validations_total' => 1,
            'credits_earned_total' => 2400,
            'gold_attempts' => 0,
        ]);

        $overview = app(DashboardService::class)->overview($user);

        $this->assertSame(0, $overview['contributions']['gold_attempts']);
        $this->assertSame(10, $overview['contributions']['gold_attempts_required']);

        $keys = array_column($overview['next_actions'], 'key');
        $this->assertContains('contributions.gold_attempts', $keys);
    }

    public function test_capability_actions_are_ranked_among_the_profile_steps(): void
    {
        $user = User::factory()->create();

        ContributorProfile::query()->create([
            'user_id' => $user->id,
            'consented_at' => now(),
            'consent_terms_version' => '2026-06-14',
            'tier' => ContributorProfile::TIER_NOVICE,
            'gold_attempts' => 0,
        ]);

        $actions = app(DashboardService::class)->overview($user)['next_actions'];
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];

        $positions = array_map(fn ($a) => $order[$a['importance']], $actions);
        $sorted = $positions;
        sort($sorted);

        $this->assertSame(
            $sorted,
            $positions,
            'A capability action must be ranked in, not appended below low-importance steps.'
        );
    }

    public function test_credits_bought_or_gifted_do_not_count_as_earned_today(): void
    {
        $user = User::factory()->create();
        $user->ensureCreditWallet();

        foreach ([
            ['daily_login', 10],
            ['wallet_purchase', 1000],
            ['transfer_in', 50],
        ] as [$source, $amount]) {
            $user->creditTransactions()->create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'type' => \App\Models\CreditTransaction::TYPE_EARNED,
                'amount' => $amount,
                'balance_after' => $amount,
                'source' => $source,
                'referenceable_type' => User::class,
                'referenceable_id' => $user->id,
            ]);
        }

        $wallet = app(DashboardService::class)->overview($user)['wallet'];

        $this->assertSame(10, $wallet['credits_earned_today']);
    }

    public function test_daily_bonus_is_null_when_no_rate_is_configured(): void
    {
        \App\Models\CreditRate::query()->where('activity_type', 'daily_login')->delete();

        $user = User::factory()->create();

        $this->assertNull(app(DashboardService::class)->overview($user)['daily_bonus']);
    }

    public function test_daily_bonus_offers_the_claim_from_the_rate_row(): void
    {
        \App\Models\CreditRate::query()->updateOrCreate(
            ['activity_type' => 'daily_login'],
            [
                'display_name' => 'Daily login',
                'credits_per_action' => 10,
                'daily_limit' => 10,
                'cooldown_minutes' => 1440,
                'is_active' => true,
            ]
        );

        $user = User::factory()->create();

        $bonus = app(DashboardService::class)->overview($user)['daily_bonus'];

        $this->assertTrue($bonus['available']);
        $this->assertSame(10.0, $bonus['credits']);
        $this->assertSame(0, $bonus['available_in_minutes']);
    }

    public function test_daily_bonus_is_withheld_while_the_cooldown_runs(): void
    {
        \App\Models\CreditRate::query()->updateOrCreate(
            ['activity_type' => 'daily_login'],
            [
                'display_name' => 'Daily login',
                'credits_per_action' => 10,
                'daily_limit' => 10,
                'cooldown_minutes' => 1440,
                'is_active' => true,
            ]
        );

        $user = User::factory()->create();
        $user->ensureCreditWallet();

        $user->creditTransactions()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'type' => \App\Models\CreditTransaction::TYPE_EARNED,
            'amount' => 10,
            'balance_after' => 10,
            'source' => 'daily_login',
            'referenceable_type' => User::class,
            'referenceable_id' => $user->id,
        ]);

        $bonus = app(DashboardService::class)->overview($user)['daily_bonus'];

        $this->assertFalse($bonus['available']);
        $this->assertGreaterThan(0, $bonus['available_in_minutes']);
        $this->assertSame(1, $bonus['streak_days']);
    }

    public function test_contributions_and_artist_are_null_when_they_do_not_apply(): void
    {
        $user = User::factory()->create();

        $overview = app(DashboardService::class)->overview($user);

        $this->assertNull($overview['contributions']);
        $this->assertNull($overview['artist']);
    }

    public function test_an_artist_gets_their_catalogue_in_the_same_payload(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $user->id]);

        Song::factory()->count(2)->create(['artist_id' => $artist->id, 'status' => 'published']);
        Song::factory()->create(['artist_id' => $artist->id, 'status' => 'pending']);
        Song::factory()->create(['artist_id' => $artist->id, 'status' => 'draft']);

        $block = app(DashboardService::class)->overview($user)['artist'];

        $this->assertNotNull($block);
        $this->assertSame(2, $block['songs_published']);
        $this->assertSame(1, $block['songs_pending_review']);
        $this->assertSame(1, $block['songs_draft']);
    }

    public function test_wallet_reports_what_was_earned_today(): void
    {
        $user = User::factory()->create();
        $user->ensureCreditWallet();

        $user->creditTransactions()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'type' => \App\Models\CreditTransaction::TYPE_EARNED,
            'amount' => 10,
            'balance_after' => 10,
            'source' => 'daily_login',
            'referenceable_type' => User::class,
            'referenceable_id' => $user->id,
        ]);

        $wallet = app(DashboardService::class)->overview($user)['wallet'];

        $this->assertSame(10, $wallet['credits_earned_today']);
    }

    public function test_the_endpoint_serves_the_widened_shape(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'wallet' => ['ugx_balance', 'credits_balance', 'credits_earned_today'],
                    'listening' => [
                        'plays_total', 'plays_30d', 'plays_previous_30d',
                        'minutes_30d', 'completed_30d', 'last_played_at',
                    ],
                    'profile' => ['completion_percentage', 'phone_verified', 'email_verified'],
                    'next_actions',
                    'capabilities',
                ],
            ]);
    }
}
