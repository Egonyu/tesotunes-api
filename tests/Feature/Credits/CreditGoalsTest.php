<?php

namespace Tests\Feature\Credits;

use App\Models\CreditMilestone;
use App\Models\CreditMilestoneClaim;
use App\Models\CreditRate;
use App\Models\User;
use App\Services\Credits\CreditMilestoneService;
use Database\Seeders\CreditMilestoneSeeder;
use Database\Seeders\CreditRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit goals.
 *
 * The old ladder counted every credit ever added — purchases included — toward
 * rewards nothing paid, "Artist verification" among them. These pin down that
 * goals now pay, pay once, and can only be reached by activity.
 */
class CreditGoalsTest extends TestCase
{
    use RefreshDatabase;

    private function goal(int $required, int $reward = 100): CreditMilestone
    {
        return CreditMilestone::create([
            'key' => 'test_'.$required.'_'.uniqid(),
            'name' => "Earn {$required}",
            'credits_required' => $required,
            'reward_type' => CreditMilestone::REWARD_CREDITS,
            'reward_value' => $reward,
            'badge_name' => 'Tester',
            'badge_icon' => '🎯',
            'badge_tier' => CreditMilestone::TIER_BRONZE,
            'is_active' => true,
        ]);
    }

    private function goalsFor(User $user): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/credits/goals')->assertOk()->json('data');
    }

    public function test_purchased_and_refunded_credits_do_not_count_toward_goals(): void
    {
        $this->goal(required: 500);
        $user = User::factory()->create();
        $user->addCredits(5000, 'wallet_purchase', 'Bought credits');
        $user->addCredits(2000, 'mobile_money_purchase', 'Bought credits');
        $user->addCredits(1000, 'store_refund', 'Refund');
        $user->addCredits(1000, 'transfer_in', 'From a friend');

        $data = $this->goalsFor($user);

        $this->assertSame(0, $data['activity_credits']);
        $this->assertSame('locked', $data['milestones'][0]['status']);
    }

    public function test_activity_credits_count_and_progress_is_reported(): void
    {
        $this->goal(required: 500);
        $user = User::factory()->create();
        $user->addCredits(150, CreditRate::SONG_PLAY_COMPLETE, 'Listened');
        $user->addCredits(100, CreditRate::DAILY_LOGIN, 'Daily login');

        $goal = $this->goalsFor($user)['milestones'][0];

        $this->assertSame('locked', $goal['status']);
        $this->assertSame(50, $goal['progress']);
        $this->assertSame(250, $goal['remaining']);
    }

    public function test_a_goal_cannot_be_claimed_before_it_is_reached(): void
    {
        $goal = $this->goal(required: 500);
        $user = User::factory()->create();
        $user->addCredits(499, CreditRate::SOCIAL_SHARE, 'Shared');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/credits/goals/{$goal->id}/claim")
            ->assertStatus(422);

        $this->assertSame(0, CreditMilestoneClaim::count());
    }

    public function test_claiming_a_reached_goal_pays_the_reward(): void
    {
        $goal = $this->goal(required: 100, reward: 10);
        $user = User::factory()->create();
        $user->addCredits(100, CreditRate::SONG_PLAY_COMPLETE, 'Listened');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/credits/goals/{$goal->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.credits_awarded', 10);

        $this->assertEquals(110, (float) $user->fresh()->creditWallet->balance);
        $this->assertSame('claimed', $this->goalsFor($user)['milestones'][0]['status']);
    }

    public function test_a_goal_pays_only_once(): void
    {
        $goal = $this->goal(required: 100, reward: 10);
        $user = User::factory()->create();
        $user->addCredits(100, CreditRate::SONG_PLAY_COMPLETE, 'Listened');

        $this->actingAs($user, 'sanctum')->postJson("/api/credits/goals/{$goal->id}/claim")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/credits/goals/{$goal->id}/claim")->assertStatus(422);

        $this->assertSame(1, CreditMilestoneClaim::where('user_id', $user->id)->count());
        $this->assertEquals(110, (float) $user->fresh()->creditWallet->balance);
    }

    public function test_a_goal_payout_does_not_count_toward_the_next_goal(): void
    {
        $first = $this->goal(required: 100, reward: 50);
        $this->goal(required: 150);
        $user = User::factory()->create();
        $user->addCredits(100, CreditRate::SONG_PLAY_COMPLETE, 'Listened');

        $this->actingAs($user, 'sanctum')->postJson("/api/credits/goals/{$first->id}/claim")->assertOk();

        $data = $this->goalsFor($user);
        $this->assertSame(100, $data['activity_credits']);
        $this->assertSame('locked', $data['milestones'][1]['status']);
    }

    public function test_an_inactive_goal_is_neither_shown_nor_claimable(): void
    {
        $goal = $this->goal(required: 10);
        $goal->update(['is_active' => false]);
        $user = User::factory()->create();
        $user->addCredits(100, CreditRate::SONG_PLAY_COMPLETE, 'Listened');

        $this->assertSame([], $this->goalsFor($user)['milestones']);
        $this->actingAs($user, 'sanctum')->postJson("/api/credits/goals/{$goal->id}/claim")->assertNotFound();
    }

    public function test_goals_require_authentication(): void
    {
        $goal = $this->goal(required: 10);

        $this->getJson('/api/credits/goals')->assertUnauthorized();
        $this->postJson("/api/credits/goals/{$goal->id}/claim")->assertUnauthorized();
    }

    public function test_the_dashboard_shows_the_lowest_unclaimed_goal_first(): void
    {
        $this->goal(required: 100, reward: 10);
        $this->goal(required: 500, reward: 25);
        $user = User::factory()->create();
        $user->addCredits(300, CreditRate::SONG_PLAY_COMPLETE, 'Listened');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/credits/dashboard')
            ->assertOk()
            ->assertJsonPath('data.wallet.goals.activity_credits', 300)
            ->assertJsonPath('data.wallet.goals.claimable', 1)
            ->assertJsonPath('data.wallet.goals.next.credits_required', 100)
            ->assertJsonPath('data.wallet.goals.next.status', 'claimable')
            ->assertJsonMissingPath('data.wallet.next_milestone');
    }

    public function test_the_seeded_ladder_is_honest(): void
    {
        $this->seed(CreditMilestoneSeeder::class);

        $names = CreditMilestone::pluck('name')->implode(' ');
        $this->assertStringNotContainsStringIgnoringCase('verification', $names);
        $this->assertStringNotContainsStringIgnoringCase('VIP', $names);
        $this->assertSame('TesoTunes Ambassador', CreditMilestone::where('credits_required', 10_000)->value('name'));
        $this->assertSame([100, 500, 1_000, 5_000, 10_000], CreditMilestone::orderBy('credits_required')->pluck('credits_required')->all());
    }

    /**
     * Every rated activity must be a deliberate decision: counted, or listed
     * here as not activity. A new rate otherwise silently counts for nothing.
     */
    public function test_every_rated_activity_is_accounted_for(): void
    {
        $this->seed(CreditRateSeeder::class);
        $notActivity = [CreditRate::REFERRAL_WELCOME];

        $unaccounted = CreditRate::pluck('activity_type')
            ->reject(fn (string $type) => in_array($type, CreditMilestoneService::ACTIVITY_SOURCES, true))
            ->reject(fn (string $type) => in_array($type, $notActivity, true))
            ->values()
            ->all();

        $this->assertSame([], $unaccounted);
    }
}
