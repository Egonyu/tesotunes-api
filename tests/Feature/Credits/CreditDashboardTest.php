<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The credits dashboard used to answer 500 for everybody.
 *
 * getUserCreditSummary asked getLoginStreak for a streak, which queried
 * UserActivityCredit — a model with no class file and no migration. Every call
 * threw, the endpoint returned 500, and the page rendered `available_credits ??
 * 0`, so people who held credits were shown a zero balance. The purchases had
 * worked; only the screen was wrong.
 */
class CreditDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_reports_the_balance_a_user_actually_holds(): void
    {
        $user = User::factory()->create();
        $user->addCredits(2656, 'wallet_purchase', 'Bought credits');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/credits/dashboard')
            ->assertOk()
            ->assertJsonPath('data.wallet.available_credits', 2656);
    }

    public function test_the_dashboard_does_not_fall_over_for_an_account_with_no_history(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/credits/dashboard')
            ->assertOk()
            ->assertJsonPath('data.wallet.available_credits', 0)
            ->assertJsonPath('data.wallet.login_streak', 0);
    }

    /** Consecutive claim days, counted off the ledger rather than a second table. */
    public function test_the_login_streak_counts_consecutive_days(): void
    {
        $user = User::factory()->create();
        $user->ensureCreditWallet();

        foreach ([0, 1, 2] as $daysAgo) {
            // created_at is not mass-assignable, so it is forced after the
            // insert — otherwise every row lands on today and the streak reads 1.
            CreditTransaction::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $user->id,
                'type' => CreditTransaction::TYPE_EARNED,
                'amount' => 10,
                'balance_after' => 10,
                'source' => 'daily_login',
                'description' => 'Daily login bonus',
            ])->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/credits/dashboard')
            ->assertOk()
            ->assertJsonPath('data.wallet.login_streak', 3);
    }

    /**
     * A gap breaks the streak. Claiming yesterday but not yet today must not
     * read as zero, or everybody shows a broken streak until the moment they
     * claim.
     */
    public function test_a_streak_survives_until_a_whole_day_is_missed(): void
    {
        $user = User::factory()->create();
        $user->ensureCreditWallet();

        foreach ([1, 2] as $daysAgo) {
            // created_at is not mass-assignable, so it is forced after the
            // insert — otherwise every row lands on today and the streak reads 1.
            CreditTransaction::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $user->id,
                'type' => CreditTransaction::TYPE_EARNED,
                'amount' => 10,
                'balance_after' => 10,
                'source' => 'daily_login',
                'description' => 'Daily login bonus',
            ])->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/credits/dashboard')
            ->assertOk()
            ->assertJsonPath('data.wallet.login_streak', 2);
    }
}
