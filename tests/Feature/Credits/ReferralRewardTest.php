<?php

namespace Tests\Feature\Credits;

use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credits\RewardRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The referral programme has never paid anybody. Registration accepted no
 * referral code, so referrer_id was never set; the listener that would have
 * awarded the credits was registered nowhere and could not be auto-discovered
 * because its handlers take untyped events; and CreditService::getRate() threw
 * "Unknown column" before reaching an award anyway.
 *
 * These pin the whole chain, end to end.
 */
class ReferralRewardTest extends TestCase
{
    use RefreshDatabase;

    private function seedReferralRates(int $signup = 500, int $welcome = 200): void
    {
        CreditRate::create([
            'activity_type' => CreditRate::REFERRAL_SIGNUP,
            'credits_per_action' => $signup,
            'is_active' => true,
        ]);

        CreditRate::create([
            'activity_type' => CreditRate::REFERRAL_WELCOME,
            'credits_per_action' => $welcome,
            'max_per_user_lifetime' => 1,
            'is_active' => true,
        ]);
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/register', array_merge([
            'name' => 'New Person',
            'email' => 'new'.uniqid().'@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides));
    }

    public function test_signing_up_with_a_code_pays_both_sides(): void
    {
        $this->seedReferralRates();

        $referrer = User::factory()->create();
        $code = $referrer->generateReferralCode();

        $this->register(['referral_code' => $code])->assertCreated();

        $newUser = User::where('referrer_id', $referrer->id)->first();

        $this->assertNotNull($newUser, 'referrer_id was never set on the new account');

        $this->assertSame(500.0, (float) CreditTransaction::where('user_id', $referrer->id)
            ->where('source', CreditRate::REFERRAL_SIGNUP)->sum('amount'));

        $this->assertSame(200.0, (float) CreditTransaction::where('user_id', $newUser->id)
            ->where('source', CreditRate::REFERRAL_WELCOME)->sum('amount'));
    }

    /**
     * Counts are scoped to the referral sources throughout: every registration
     * also pays an unrelated 200-credit welcome bonus, via a listener that has
     * always worked because its handler takes a typed event — the very thing
     * the referral listener lacked.
     */
    private function referralCreditsPaid(): float
    {
        return (float) CreditTransaction::query()
            ->whereIn('source', [CreditRate::REFERRAL_SIGNUP, CreditRate::REFERRAL_WELCOME])
            ->sum('amount');
    }

    public function test_an_unknown_code_does_not_block_the_signup(): void
    {
        $this->seedReferralRates();

        // Growth must never hinge on a mistyped link.
        $this->register(['referral_code' => 'NOT-A-REAL-CODE'])->assertCreated();

        $this->assertSame(0.0, $this->referralCreditsPaid());
        $this->assertSame(0, User::whereNotNull('referrer_id')->count());
    }

    public function test_registering_without_a_code_still_works(): void
    {
        $this->seedReferralRates();

        $this->register()->assertCreated();

        $this->assertSame(0.0, $this->referralCreditsPaid());
    }

    /** With no rate row the activity simply pays nothing — it is not an error. */
    public function test_no_rate_configured_means_no_payout_and_no_failure(): void
    {
        $referrer = User::factory()->create();
        $code = $referrer->generateReferralCode();

        $this->register(['referral_code' => $code])->assertCreated();

        $this->assertSame(0.0, $this->referralCreditsPaid());
        // Nothing was owed, so nothing should be reported as missing.
        $this->assertDatabaseCount('credit_issues', 0);
        $this->assertNotNull(User::where('referrer_id', $referrer->id)->first());
    }

    public function test_a_rate_outside_its_campaign_window_does_not_pay(): void
    {
        CreditRate::create([
            'activity_type' => CreditRate::REFERRAL_SIGNUP,
            'credits_per_action' => 500,
            'is_active' => true,
            'starts_at' => now()->addWeek(),
        ]);

        $referrer = User::factory()->create();

        $outcome = app(RewardRuleService::class)->award($referrer, CreditRate::REFERRAL_SIGNUP);

        $this->assertFalse($outcome->wasAwarded());
        $this->assertTrue($outcome->wasWithheld());
        $this->assertSame('inactive', $outcome->status);
    }

    public function test_the_welcome_bonus_can_only_ever_be_claimed_once(): void
    {
        $this->seedReferralRates();

        $user = User::factory()->create();
        $rewards = app(RewardRuleService::class);

        $this->assertTrue($rewards->award($user, CreditRate::REFERRAL_WELCOME)->wasAwarded());

        $second = $rewards->award($user, CreditRate::REFERRAL_WELCOME);

        $this->assertFalse($second->wasAwarded());
        $this->assertSame('lifetime_cap', $second->status);
    }

    public function test_a_daily_ceiling_pays_the_part_that_still_fits(): void
    {
        CreditRate::create([
            'activity_type' => 'social_like',
            'credits_per_action' => 10,
            'daily_limit' => 25,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $rewards = app(RewardRuleService::class);

        $rewards->award($user, 'social_like');   // 10
        $rewards->award($user, 'social_like');   // 20
        $third = $rewards->award($user, 'social_like'); // only 5 left

        $this->assertTrue($third->wasAwarded());
        $this->assertSame(5.0, $third->credits);

        $fourth = $rewards->award($user, 'social_like');
        $this->assertSame('daily_limit', $fourth->status);

        $this->assertSame(25.0, (float) CreditTransaction::where('user_id', $user->id)
            ->where('source', 'social_like')->sum('amount'));
    }
}
