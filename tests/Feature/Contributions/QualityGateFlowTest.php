<?php

namespace Tests\Feature\Contributions;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Services\ConsentService;
use App\Modules\Contributions\Services\ReputationService;
use App\Modules\Contributions\Services\SubmissionService;
use App\Modules\Contributions\Services\ValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QualityGateFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function consentedUser(): User
    {
        $user = User::factory()->create();
        app(ConsentService::class)->recordConsent($user);

        return $user;
    }

    private function translateTask(bool $gold = false, ?string $goldAnswer = null): ContributionTask
    {
        return ContributionTask::create([
            'type' => ContributionTask::TYPE_TRANSLATE,
            'source_lang' => 'teo',
            'target_lang' => 'en',
            'region' => 'ug',
            'register' => 'lyrical',
            'prompt_text' => 'Eong ajokis',
            'is_gold' => $gold,
            'gold_answer' => $goldAnswer,
            'redundancy_target' => 3,
            'status' => ContributionTask::STATUS_OPEN,
        ]);
    }

    public function test_gold_submission_is_scored_and_updates_reputation(): void
    {
        $task = $this->translateTask(gold: true, goldAnswer: 'I am fine');
        $user = $this->consentedUser();

        app(SubmissionService::class)->submit($user, $task, 'i am fine.');

        $profile = ContributorProfile::where('user_id', $user->id)->first();
        $this->assertSame(1, $profile->gold_attempts);
        $this->assertSame(1, $profile->gold_passed);
        $this->assertEquals(100.0, (float) $profile->gold_pass_rate);

        $submission = ContributionSubmission::where('contribution_task_id', $task->id)->first();
        $this->assertTrue((bool) $submission->gold_passed);
    }

    public function test_gold_passes_on_an_accepted_dialectal_variant(): void
    {
        $task = $this->translateTask(gold: true, goldAnswer: 'I am fine');
        $task->forceFill(['gold_answers' => ['I am well', 'i am okay']])->save();
        $user = $this->consentedUser();

        // Not the primary answer, but a listed accepted variant → passes.
        app(SubmissionService::class)->submit($user, $task, 'I am well');

        $profile = ContributorProfile::where('user_id', $user->id)->first();
        $this->assertSame(1, $profile->gold_passed);
    }

    public function test_wrong_gold_answer_does_not_pass(): void
    {
        $task = $this->translateTask(gold: true, goldAnswer: 'I am fine');
        $user = $this->consentedUser();

        app(SubmissionService::class)->submit($user, $task, 'completely different');

        $profile = ContributorProfile::where('user_id', $user->id)->first();
        $this->assertSame(1, $profile->gold_attempts);
        $this->assertSame(0, $profile->gold_passed);
    }

    public function test_tier_promotes_to_reviewer_after_consistent_gold_passes(): void
    {
        $user = $this->consentedUser();
        $reputation = app(ReputationService::class);

        foreach (range(1, 10) as $i) {
            $reputation->recordGoldResult($user, true);
        }

        $profile = ContributorProfile::where('user_id', $user->id)->first();
        $this->assertEquals(100.0, (float) $profile->gold_pass_rate);
        $this->assertSame(ContributorProfile::TIER_REVIEWER, $profile->tier);
    }

    public function test_a_contributor_cannot_validate_their_own_submission(): void
    {
        $task = $this->translateTask();
        $author = $this->consentedUser();
        app(SubmissionService::class)->submit($author, $task, 'I am fine');
        $submission = ContributionSubmission::where('contribution_task_id', $task->id)->first();

        $this->expectException(\DomainException::class);
        app(ValidationService::class)->validate($author, $submission, 'agree');
    }

    public function test_peer_approval_accepts_a_submission_and_mints_a_corpus_pair(): void
    {
        config(['contributions.acceptance.min_validations' => 2, 'contributions.acceptance.approval_threshold' => 2.0]);

        $task = $this->translateTask();
        $submissions = app(SubmissionService::class);

        $author = $this->consentedUser();
        $submissions->submit($author, $task, 'I greet you');
        $submissions->submit($this->consentedUser(), $task, 'something else');

        $winner = ContributionSubmission::where('contribution_task_id', $task->id)
            ->where('user_id', $author->id)->first();

        // Two independent peers agree → weighted approval 2.0 clears the gate.
        $validations = app(ValidationService::class);
        $validations->validate($this->consentedUser(), $winner, 'agree');
        $validations->validate($this->consentedUser(), $winner, 'agree');

        $winner->refresh();
        $task->refresh();

        $this->assertSame(ContributionSubmission::STATUS_ACCEPTED, $winner->status);
        // Multi-variant: acceptance mints a pair but keeps the task open so other
        // dialectal variants can still be gathered and accepted — it no longer
        // hard-closes on the first acceptance.
        $this->assertNotSame(ContributionTask::STATUS_CLOSED, $task->status);

        $pair = CorpusPair::where('contribution_submission_id', $winner->id)->first();
        $this->assertNotNull($pair);
        $this->assertSame('Eong ajokis', $pair->ateso_text); // source (teo) side
        $this->assertSame('I greet you', $pair->en_text);     // accepted translation
        $this->assertSame('CC-BY-SA-4.0', $pair->license_version);

        // The other, un-reviewed submission is a *different* variant with no
        // votes — it stays open for review, not superseded.
        $this->assertSame(
            ContributionSubmission::STATUS_SUBMITTED,
            ContributionSubmission::where('contribution_task_id', $task->id)
                ->where('user_id', '!=', $author->id)->first()->status
        );
    }

    public function test_two_distinct_dialect_variants_both_get_accepted(): void
    {
        config(['contributions.acceptance.min_validations' => 1, 'contributions.acceptance.approval_threshold' => 1.0]);

        $task = $this->translateTask();
        $submissions = app(SubmissionService::class);
        $validations = app(ValidationService::class);

        // Two contributors give genuinely different (both valid) translations.
        $a = $this->consentedUser();
        $b = $this->consentedUser();
        $submissions->submit($a, $task, 'I greet you', ['dialect' => 'soroti']);
        $submissions->submit($b, $task, 'greetings to you', ['dialect' => 'kumi']);

        $subA = ContributionSubmission::where('contribution_task_id', $task->id)->where('user_id', $a->id)->first();
        $subB = ContributionSubmission::where('contribution_task_id', $task->id)->where('user_id', $b->id)->first();

        // A peer approves A; another marks B a valid variant (different dialect).
        $validations->validate($this->consentedUser(), $subA, 'agree');
        $validations->validate($this->consentedUser(), $subB, 'valid_variant');

        // Both variants are accepted as separate, dialect-tagged corpus pairs.
        $this->assertSame(2, CorpusPair::where('contribution_submission_id', $subA->id)
            ->orWhere('contribution_submission_id', $subB->id)->count());
        $this->assertSame('soroti', CorpusPair::where('contribution_submission_id', $subA->id)->value('dialect'));
        $this->assertSame('kumi', CorpusPair::where('contribution_submission_id', $subB->id)->value('dialect'));
    }

    public function test_gold_tasks_never_mint_corpus_pairs(): void
    {
        config(['contributions.acceptance.min_validations' => 1, 'contributions.acceptance.approval_threshold' => 0.5]);

        $task = $this->translateTask(gold: true, goldAnswer: 'I am fine');
        app(SubmissionService::class)->submit($this->consentedUser(), $task, 'I am fine');
        $submission = ContributionSubmission::where('contribution_task_id', $task->id)->first();

        app(ValidationService::class)->validate($this->consentedUser(), $submission, 'agree');

        $this->assertSame(0, CorpusPair::count());
    }
}
