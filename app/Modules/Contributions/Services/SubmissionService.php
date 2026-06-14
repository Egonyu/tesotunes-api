<?php

namespace App\Modules\Contributions\Services;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Records contributor translations against open tasks, enforcing the
 * participation guards: consent, task availability, one-answer-per-task, and
 * the redundancy target that closes a task once enough answers are in.
 * Gold-task answers are scored on submit (reputation); peer acceptance of
 * normal tasks lands in AcceptanceService. Reward settles in 9.4.
 */
class SubmissionService
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly ReputationService $reputation,
    ) {}

    /**
     * @throws \DomainException on a guard violation (controller maps to 422)
     */
    public function submit(User $user, ContributionTask $task, string $rawText): ContributionSubmission
    {
        if ($this->consent->needsConsent($user)) {
            throw new \DomainException('You must accept the contribution data terms before submitting.');
        }

        if (! $task->isOpen()) {
            throw new \DomainException('This task is no longer accepting submissions.');
        }

        $text = trim($rawText);
        if ($text === '') {
            throw new \DomainException('A translation is required.');
        }

        if ($this->hasSubmitted($user, $task)) {
            throw new \DomainException('You have already submitted a translation for this task.');
        }

        return DB::transaction(function () use ($user, $task, $text) {
            /** @var ContributionTask $task */
            $task = ContributionTask::query()->lockForUpdate()->findOrFail($task->id);

            $submission = new ContributionSubmission([
                'raw_text' => $text,
                // Normalized (house-orthography) form is computed in 9.3; until
                // then it mirrors the raw text so the column is always populated.
                'normalized_text' => $text,
                'region' => $task->region,
                'status' => ContributionSubmission::STATUS_SUBMITTED,
                'is_gold_attempt' => $task->isGold(),
            ]);
            $submission->task()->associate($task);
            $submission->user()->associate($user);

            // Gold salting: score the answer against the hidden gold answer and
            // fold the result into the contributor's reputation immediately.
            if ($task->isGold()) {
                $passed = TextNormalizer::matches($text, (string) $task->gold_answer);
                $submission->gold_passed = $passed;
                $submission->save();
                $this->reputation->recordGoldResult($user, $passed);
            } else {
                $submission->save();
            }

            $task->forceFill([
                'submission_count' => $task->submission_count + 1,
                'status' => ($task->submission_count + 1) >= $task->redundancy_target
                    ? ContributionTask::STATUS_FULFILLED
                    : ContributionTask::STATUS_IN_PROGRESS,
            ])->save();

            ContributorProfile::query()->where('user_id', $user->id)
                ->update(['submissions_total' => DB::raw('submissions_total + 1')]);

            return $submission;
        });
    }

    public function hasSubmitted(User $user, ContributionTask $task): bool
    {
        return ContributionSubmission::query()
            ->where('contribution_task_id', $task->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
