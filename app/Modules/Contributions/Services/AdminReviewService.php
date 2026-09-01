<?php

namespace App\Modules\Contributions\Services;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionValidation;
use Illuminate\Support\Facades\DB;

/**
 * Operator moderation of the review backlog. An admin verdict is recorded as a
 * validation like any other, but flagged `admin_review` in metadata so it
 * (a) carries operator weight, (b) satisfies the quorum on its own, and
 * (c) is never rewarded. This is what keeps a thin contributor pool from
 * deadlocking: without an operator release valve, submissions sit at
 * `submitted` forever and no contributor is ever paid.
 */
class AdminReviewService
{
    public function __construct(private readonly AcceptanceService $acceptance) {}

    private const VERDICTS = [
        ContributionValidation::VERDICT_AGREE,
        ContributionValidation::VERDICT_MINOR_FIX,
        ContributionValidation::VERDICT_VALID_VARIANT,
        ContributionValidation::VERDICT_REJECT,
    ];

    /**
     * Record one operator verdict and re-evaluate the parent task.
     *
     * @throws \DomainException on a guard violation (controller maps to 422)
     */
    public function review(
        User $admin,
        ContributionSubmission $submission,
        string $verdict,
        ?string $suggestedFix = null
    ): ContributionValidation {
        $validation = $this->record($admin, $submission, $verdict, $suggestedFix);

        $this->acceptance->evaluate($submission->task);

        return $validation;
    }

    /**
     * Apply one verdict across many submissions, then evaluate each affected
     * task once. Per-item failures are collected rather than aborting the batch,
     * so one stale uuid can't sink an operator's whole sweep.
     *
     * @param  array<int, string>  $uuids
     * @return array{reviewed: int, skipped: int, accepted: int, errors: array<int, array{uuid: string, message: string}>}
     */
    public function bulkReview(User $admin, array $uuids, string $verdict, ?string $suggestedFix = null): array
    {
        $submissions = ContributionSubmission::query()
            ->with('task')
            ->whereIn('uuid', $uuids)
            ->get();

        $reviewed = 0;
        $skipped = 0;
        $errors = [];
        $tasks = [];

        foreach ($submissions as $submission) {
            try {
                $this->record($admin, $submission, $verdict, $suggestedFix);
                $reviewed++;

                if ($submission->task) {
                    $tasks[$submission->task->id] = $submission->task;
                }
            } catch (\DomainException $e) {
                $skipped++;
                $errors[] = ['uuid' => $submission->uuid, 'message' => $e->getMessage()];
            }
        }

        // Evaluate each touched task once — acceptance is idempotent, but a task
        // with many reviewed submissions shouldn't be re-scored per submission.
        $accepted = 0;
        foreach ($tasks as $task) {
            $accepted += $this->acceptance->evaluate($task)->count();
        }

        return [
            'reviewed' => $reviewed,
            'skipped' => $skipped,
            'accepted' => $accepted,
            'errors' => $errors,
        ];
    }

    /**
     * Persist the operator verdict. Guards mirror peer validation where they
     * still make sense: no self-review, no double-voting, only open work.
     *
     * @throws \DomainException
     */
    private function record(
        User $admin,
        ContributionSubmission $submission,
        string $verdict,
        ?string $suggestedFix
    ): ContributionValidation {
        if (! in_array($verdict, self::VERDICTS, true)) {
            throw new \DomainException('Invalid verdict.');
        }

        if ((int) $submission->user_id === (int) $admin->id) {
            throw new \DomainException('You cannot review your own submission.');
        }

        if ($submission->status !== ContributionSubmission::STATUS_SUBMITTED) {
            throw new \DomainException('This submission is no longer open for review.');
        }

        $alreadyReviewed = ContributionValidation::query()
            ->where('contribution_submission_id', $submission->id)
            ->where('validator_user_id', $admin->id)
            ->exists();

        if ($alreadyReviewed) {
            throw new \DomainException('You have already reviewed this submission.');
        }

        return DB::transaction(function () use ($admin, $submission, $verdict, $suggestedFix) {
            $validation = new ContributionValidation([
                'verdict' => $verdict,
                'suggested_fix' => $suggestedFix,
                'weight' => (float) config('contributions.acceptance.admin_weight', 2.0),
                'metadata' => ['admin_review' => true],
            ]);
            $validation->submission()->associate($submission);
            $validation->validator()->associate($admin);
            $validation->save();

            return $validation;
        });
    }
}
