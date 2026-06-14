<?php

namespace App\Modules\Contributions\Services;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use Illuminate\Support\Facades\DB;

/**
 * Records contributor translations against open tasks, enforcing the
 * participation guards: consent, task availability, one-answer-per-task, and
 * the redundancy target that closes a task once enough answers are in.
 * Acceptance + scoring + reward live in 9.3/9.4.
 */
class SubmissionService
{
    public function __construct(private readonly ConsentService $consent) {}

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
            $submission->save();

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
