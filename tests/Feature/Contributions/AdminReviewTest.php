<?php

namespace Tests\Feature\Contributions;

use App\Models\Commerce\Settlement;
use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Services\AdminReviewService;
use App\Modules\Contributions\Services\ConsentService;
use App\Modules\Contributions\Services\SubmissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminReviewTest extends TestCase
{
    use DatabaseTransactions;

    private function consentedUser(): User
    {
        $user = User::factory()->create();
        app(ConsentService::class)->recordConsent($user);

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
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

    private function submissionFrom(User $author): ContributionSubmission
    {
        $task = $this->task();
        app(SubmissionService::class)->submit($author, $task, 'I greet you');

        return ContributionSubmission::where('contribution_task_id', $task->id)
            ->where('user_id', $author->id)->firstOrFail();
    }

    public function test_a_single_admin_verdict_accepts_and_pays_without_peer_quorum(): void
    {
        config(['contributions.acceptance.min_validations' => 2]);

        $author = $this->consentedUser();
        $submission = $this->submissionFrom($author);

        app(AdminReviewService::class)->review($this->admin(), $submission, 'agree');

        $this->assertSame(
            ContributionSubmission::STATUS_ACCEPTED,
            $submission->refresh()->status,
            'One operator verdict must clear the quorum on its own.'
        );

        $profile = ContributorProfile::where('user_id', $author->id)->first();
        $this->assertSame(200, (int) $profile->credits_earned_total);
    }

    public function test_admin_reviewers_are_never_paid_for_their_verdicts(): void
    {
        $admin = $this->admin();
        $submission = $this->submissionFrom($this->consentedUser());

        app(AdminReviewService::class)->review($admin, $submission, 'agree');

        $this->assertSame(0, Settlement::where('beneficiary_user_id', $admin->id)
            ->where('kind', 'validation_accepted')->count());
    }

    public function test_admin_cannot_review_the_same_submission_twice(): void
    {
        $admin = $this->admin();
        $submission = $this->submissionFrom($this->consentedUser());

        app(AdminReviewService::class)->review($admin, $submission, 'agree');

        $this->expectException(\DomainException::class);
        app(AdminReviewService::class)->review($admin, $submission->refresh(), 'agree');
    }

    public function test_admin_cannot_review_their_own_submission(): void
    {
        $admin = $this->admin();
        app(ConsentService::class)->recordConsent($admin);
        $submission = $this->submissionFrom($admin);

        $this->expectException(\DomainException::class);
        app(AdminReviewService::class)->review($admin, $submission, 'agree');
    }

    public function test_a_rejecting_admin_verdict_does_not_accept(): void
    {
        $submission = $this->submissionFrom($this->consentedUser());

        app(AdminReviewService::class)->review($this->admin(), $submission, 'reject');

        $this->assertSame(ContributionSubmission::STATUS_SUBMITTED, $submission->refresh()->status);
    }

    public function test_bulk_review_accepts_many_and_reports_per_item_failures(): void
    {
        $admin = $this->admin();
        $good = $this->submissionFrom($this->consentedUser());
        $alsoGood = $this->submissionFrom($this->consentedUser());

        $result = app(AdminReviewService::class)->bulkReview(
            $admin,
            [$good->uuid, $alsoGood->uuid, '00000000-0000-4000-8000-000000000000'],
            'agree'
        );

        $this->assertSame(2, $result['reviewed']);
        $this->assertSame(2, $result['accepted']);
        $this->assertSame(ContributionSubmission::STATUS_ACCEPTED, $good->refresh()->status);
        $this->assertSame(ContributionSubmission::STATUS_ACCEPTED, $alsoGood->refresh()->status);
    }

    public function test_admin_gate_can_be_switched_off(): void
    {
        config([
            'contributions.acceptance.min_validations' => 2,
            'contributions.acceptance.admin_clears_gate' => false,
        ]);

        $submission = $this->submissionFrom($this->consentedUser());
        app(AdminReviewService::class)->review($this->admin(), $submission, 'agree');

        $this->assertSame(ContributionSubmission::STATUS_SUBMITTED, $submission->refresh()->status);
    }

    public function test_backlog_endpoint_lists_every_submission_with_review_state(): void
    {
        $submission = $this->submissionFrom($this->consentedUser());

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/contributions/admin/submissions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['uuid' => $submission->uuid])
            ->assertJsonPath('data.0.validations_count', 0)
            ->assertJsonPath('data.0.status', ContributionSubmission::STATUS_SUBMITTED);
    }

    public function test_bulk_review_endpoint_requires_admin(): void
    {
        $submission = $this->submissionFrom($this->consentedUser());

        $this->actingAs($this->consentedUser(), 'sanctum')
            ->postJson('/api/contributions/admin/submissions/bulk-review', [
                'uuids' => [$submission->uuid],
                'verdict' => 'agree',
            ])
            ->assertForbidden();
    }
}
