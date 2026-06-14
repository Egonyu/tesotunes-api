<?php

namespace App\Modules\Contributions\Services;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionValidation;
use App\Modules\Contributions\Models\ContributorProfile;
use Illuminate\Support\Facades\DB;

/**
 * Records peer verdicts on submissions and re-evaluates acceptance after each
 * vote. Enforces the collusion guards: a contributor can't validate their own
 * work, nor the work of accounts they referred.
 */
class ValidationService
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly ReputationService $reputation,
        private readonly AcceptanceService $acceptance,
    ) {}

    private const VERDICTS = [
        ContributionValidation::VERDICT_AGREE,
        ContributionValidation::VERDICT_MINOR_FIX,
        ContributionValidation::VERDICT_VALID_VARIANT,
        ContributionValidation::VERDICT_REJECT,
    ];

    /**
     * @throws \DomainException on a guard violation (controller maps to 422)
     */
    public function validate(
        User $validator,
        ContributionSubmission $submission,
        string $verdict,
        ?string $suggestedFix = null
    ): ContributionValidation {
        if (! in_array($verdict, self::VERDICTS, true)) {
            throw new \DomainException('Invalid verdict.');
        }

        if ($this->consent->needsConsent($validator)) {
            throw new \DomainException('You must accept the contribution data terms before validating.');
        }

        if ((int) $submission->user_id === (int) $validator->id) {
            throw new \DomainException('You cannot validate your own submission.');
        }

        // Collusion guard: you cannot validate the work of someone you referred.
        $submitter = $submission->user;
        if ($submitter && (int) ($submitter->referrer_id ?? 0) === (int) $validator->id) {
            throw new \DomainException('You cannot validate a contributor you referred.');
        }

        if ($submission->status !== ContributionSubmission::STATUS_SUBMITTED) {
            throw new \DomainException('This submission is no longer open for validation.');
        }

        if ($this->hasValidated($validator, $submission)) {
            throw new \DomainException('You have already validated this submission.');
        }

        $validation = DB::transaction(function () use ($validator, $submission, $verdict, $suggestedFix) {
            $validation = new ContributionValidation([
                'verdict' => $verdict,
                'suggested_fix' => $suggestedFix,
                'weight' => $this->reputation->weightFor($validator),
            ]);
            $validation->submission()->associate($submission);
            $validation->validator()->associate($validator);
            $validation->save();

            ContributorProfile::query()->where('user_id', $validator->id)
                ->update(['validations_total' => DB::raw('validations_total + 1')]);

            return $validation;
        });

        // Re-evaluate acceptance now that a new vote is in.
        $this->acceptance->evaluate($submission->task);

        return $validation;
    }

    public function hasValidated(User $validator, ContributionSubmission $submission): bool
    {
        return ContributionValidation::query()
            ->where('contribution_submission_id', $submission->id)
            ->where('validator_user_id', $validator->id)
            ->exists();
    }
}
