<?php

namespace Tests\Feature\Contributions;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Services\ConsentService;
use App\Modules\Contributions\Services\SubmissionService;
use App\Modules\Contributions\Support\ContributionsModule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContributorProfilePendingTest extends TestCase
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

    public function test_profile_reports_pending_submissions_and_estimated_credits(): void
    {
        ContributionsModule::setEnabled(true);
        config(['contributions.rewards.per_pair_ugx' => 200]);

        $author = $this->consentedUser();
        app(SubmissionService::class)->submit($author, $this->task(), 'I greet you');
        app(SubmissionService::class)->submit($author, $this->task(), 'I greet you too');

        Sanctum::actingAs($author);

        $this->getJson('/api/contributions/profile')
            ->assertOk()
            ->assertJsonPath('data.submissions_pending', 2)
            ->assertJsonPath('data.pending_estimate_credits', 400)
            ->assertJsonPath('data.credits_earned_total', 0);
    }

    public function test_pending_is_zero_when_no_unsettled_work(): void
    {
        ContributionsModule::setEnabled(true);

        Sanctum::actingAs($this->consentedUser());

        $this->getJson('/api/contributions/profile')
            ->assertOk()
            ->assertJsonPath('data.submissions_pending', 0)
            ->assertJsonPath('data.pending_estimate_credits', 0);
    }
}
