<?php

namespace Tests\Feature\Contributions;

use App\Models\Commerce\Settlement;
use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Services\ConsentService;
use App\Modules\Contributions\Services\SubmissionService;
use App\Modules\Contributions\Services\ValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RewardSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private function consentedUser(): User
    {
        $user = User::factory()->create();
        app(ConsentService::class)->recordConsent($user);

        return $user;
    }

    private function task(): ContributionTask
    {
        return ContributionTask::create([
            'type' => ContributionTask::TYPE_TRANSLATE,
            'source_lang' => 'teo',
            'target_lang' => 'en',
            'region' => 'ug',
            'register' => 'lyrical',
            'prompt_text' => 'Eong ajokis',
            'redundancy_target' => 3,
            'status' => ContributionTask::STATUS_OPEN,
        ]);
    }

    /**
     * Drive a task to acceptance and return the winning submission.
     */
    private function acceptOne(User $author, array $validators): ContributionSubmission
    {
        config(['contributions.acceptance.min_validations' => 2, 'contributions.acceptance.approval_threshold' => 2.0]);
        $task = $this->task();

        app(SubmissionService::class)->submit($author, $task, 'I greet you');

        $winner = ContributionSubmission::where('contribution_task_id', $task->id)
            ->where('user_id', $author->id)->firstOrFail();

        foreach ($validators as $v) {
            app(ValidationService::class)->validate($v, $winner, 'agree');
        }

        return $winner->refresh();
    }

    public function test_accepted_translation_pays_the_translator_via_a_cleared_settlement(): void
    {
        $author = $this->consentedUser();
        $winner = $this->acceptOne($author, [$this->consentedUser(), $this->consentedUser()]);

        $this->assertTrue((bool) $winner->settled);

        $this->assertDatabaseHas('settlements', [
            'beneficiary_user_id' => $author->id,
            'vertical' => Settlement::VERTICAL_CONTRIBUTIONS,
            'kind' => 'translation_accepted',
            'status' => Settlement::STATUS_CLEARED,
        ]);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $author->id,
            'source' => 'contribution_translation',
        ]);

        $profile = ContributorProfile::where('user_id', $author->id)->first();
        $this->assertSame(200, (int) $profile->credits_earned_total);
    }

    public function test_agreeing_validators_are_rewarded_at_half_rate(): void
    {
        $author = $this->consentedUser();
        $validator = $this->consentedUser();
        $this->acceptOne($author, [$validator, $this->consentedUser()]);

        $settlement = Settlement::where('beneficiary_user_id', $validator->id)
            ->where('kind', 'validation_accepted')->first();

        $this->assertNotNull($settlement);
        // 50% of the 200-credit per-pair rate.
        $this->assertSame(100, (int) $settlement->gross_credits);
    }

    public function test_reward_is_idempotent(): void
    {
        $author = $this->consentedUser();
        $winner = $this->acceptOne($author, [$this->consentedUser(), $this->consentedUser()]);

        // Re-running acceptance must not pay a second time.
        app(\App\Modules\Contributions\Services\AcceptanceService::class)->evaluate($winner->task);

        $this->assertSame(1, Settlement::where('beneficiary_user_id', $author->id)
            ->where('kind', 'translation_accepted')->count());
    }

    public function test_trusted_tier_earns_the_multiplier(): void
    {
        $author = $this->consentedUser();
        ContributorProfile::where('user_id', $author->id)->update(['tier' => ContributorProfile::TIER_TRUSTED]);

        $this->acceptOne($author, [$this->consentedUser(), $this->consentedUser()]);

        $settlement = Settlement::where('beneficiary_user_id', $author->id)
            ->where('kind', 'translation_accepted')->first();
        // 200 * 1.3 trusted multiplier.
        $this->assertSame(260, (int) $settlement->gross_credits);
    }

    public function test_per_contributor_daily_cap_stops_reward_but_still_settles_the_submission(): void
    {
        config(['contributions.rewards.per_contributor_daily_cap' => 0]);

        $author = $this->consentedUser();
        $winner = $this->acceptOne($author, [$this->consentedUser(), $this->consentedUser()]);

        $this->assertTrue((bool) $winner->settled);
        $this->assertSame(0, Settlement::where('beneficiary_user_id', $author->id)
            ->where('kind', 'translation_accepted')->count());
    }
}
