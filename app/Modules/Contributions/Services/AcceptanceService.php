<?php

namespace App\Modules\Contributions\Services;

use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Decides when a task's answers have converged enough to accept one and mint a
 * corpus pair. Acceptance = enough peer validations + weighted approval over
 * threshold, with a bonus for independent translators who agree. Gold tasks
 * never mint pairs (their answer is already known — they only score people).
 */
class AcceptanceService
{
    public function __construct(private readonly RewardService $rewards) {}

    /**
     * Evaluate a task; accept the winning submission and mint a corpus pair if
     * the gate is cleared. Idempotent and safe to call after every validation.
     */
    public function evaluate(ContributionTask $task): ?CorpusPair
    {
        if ($task->isGold()) {
            return null;
        }

        return DB::transaction(function () use ($task) {
            /** @var ContributionTask $task */
            $task = ContributionTask::query()->lockForUpdate()->find($task->id);

            if (! $task || $task->status === ContributionTask::STATUS_CLOSED) {
                return null;
            }

            $submissions = $task->submissions()->with(['validations.validator', 'user'])->get();
            if ($submissions->isEmpty()) {
                return null;
            }

            $cfg = config('contributions.acceptance');
            $minValidations = (int) ($cfg['min_validations'] ?? 2);
            $threshold = (float) ($cfg['approval_threshold'] ?? 2.0);
            $convergenceBonus = (float) ($cfg['convergence_bonus'] ?? 10);

            $best = null;
            $bestApproval = 0.0;

            foreach ($submissions as $submission) {
                if ($submission->status !== ContributionSubmission::STATUS_SUBMITTED) {
                    continue;
                }

                $validations = $submission->validations;
                if ($validations->count() < $minValidations) {
                    continue;
                }

                $approval = 0.0;
                foreach ($validations as $v) {
                    $approval += match ($v->verdict) {
                        'agree', 'minor_fix' => (float) $v->weight,
                        'reject' => -(float) $v->weight,
                        default => 0.0,
                    };
                }

                if ($approval < $threshold) {
                    continue;
                }

                $convergence = $this->convergenceCount($submissions, $submission);
                $score = min(100.0, ($approval * 20) + ($convergence * $convergenceBonus));

                if ($best === null || $approval > $bestApproval) {
                    $best = ['submission' => $submission, 'score' => round($score, 2)];
                    $bestApproval = $approval;
                }
            }

            if ($best === null) {
                return null;
            }

            return $this->accept($task, $best['submission'], $best['score'], $submissions);
        });
    }

    private function accept(
        ContributionTask $task,
        ContributionSubmission $winner,
        float $score,
        $allSubmissions
    ): CorpusPair {
        $winner->forceFill([
            'status' => ContributionSubmission::STATUS_ACCEPTED,
            'agreement_score' => $score,
        ])->save();

        foreach ($allSubmissions as $other) {
            if ($other->id !== $winner->id && $other->status === ContributionSubmission::STATUS_SUBMITTED) {
                $other->forceFill(['status' => ContributionSubmission::STATUS_SUPERSEDED])->save();
            }
        }

        $task->forceFill(['status' => ContributionTask::STATUS_CLOSED])->save();

        ContributorProfile::query()->where('user_id', $winner->user_id)
            ->update(['submissions_accepted' => DB::raw('submissions_accepted + 1')]);

        [$enText, $atesoText] = $this->orientPair($task, $winner);

        $pair = new CorpusPair([
            'en_text' => $enText,
            'ateso_text' => $atesoText,
            'register' => $task->register,
            'region' => $task->region,
            'quality_score' => $score,
            'license_version' => config('contributions.license_version'),
            'provenance' => [
                'task_uuid' => $task->uuid,
                'accepted_user_id' => $winner->user_id,
                'contributor_ids' => $allSubmissions->pluck('user_id')->unique()->values()->all(),
                'validator_ids' => $winner->validations->pluck('validator_user_id')->unique()->values()->all(),
            ],
        ]);
        $pair->submission()->associate($winner);
        if ($task->source_type && $task->source_id) {
            $pair->source()->associate($task->source);
        }
        $pair->save();

        // Close the loop: pay the translator and the validators who approved.
        $this->rewards->rewardAcceptance($winner);

        return $pair;
    }

    /**
     * Resolve which side of the pair is English. The task's prompt_text is the
     * source; the winning submission is the target translation.
     *
     * @return array{0: string, 1: string} [en_text, ateso_text]
     */
    private function orientPair(ContributionTask $task, ContributionSubmission $winner): array
    {
        $source = (string) $task->prompt_text;
        $target = (string) $winner->normalized_text;

        if ($task->source_lang === 'en') {
            return [$source, $target];
        }

        // Source is Ateso (the common lyric case) → submission is the English side.
        return [$target, $source];
    }

    /**
     * How many OTHER submissions on the task normalize to the same text.
     */
    private function convergenceCount($submissions, ContributionSubmission $submission): int
    {
        $key = TextNormalizer::key((string) $submission->normalized_text);
        if ($key === '') {
            return 0;
        }

        return $submissions
            ->filter(fn ($s) => $s->id !== $submission->id)
            ->filter(fn ($s) => TextNormalizer::key((string) $s->normalized_text) === $key)
            ->count();
    }
}
