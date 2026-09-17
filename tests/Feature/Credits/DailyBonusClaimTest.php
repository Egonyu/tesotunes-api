<?php

namespace Tests\Feature\Credits;

use App\Models\CreditRate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CreditService read $outcome->awarded, a property RewardOutcome never had
 * (it is wasAwarded()), so every claim since 2026-09-05 answered 500.
 */
class DailyBonusClaimTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_user_can_claim_the_daily_bonus_once_a_day(): void
    {
        CreditRate::updateOrCreate(
            ['activity_type' => 'daily_login'],
            [
                'display_name' => 'Daily login',
                'credits_per_action' => 10,
                'daily_limit' => 10,
                'cooldown_minutes' => 1440,
                'is_active' => true,
            ],
        );
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'sanctum')->postJson('/api/credits/claim-daily-bonus')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEquals(10, (float) $user->fresh()->credit_balance);

        $this->actingAs($user, 'sanctum')->postJson('/api/credits/claim-daily-bonus')
            ->assertStatus(422)
            ->assertJsonMissingPath('error');

        $this->assertEquals(10, (float) $user->fresh()->credit_balance);
    }
}
