<?php

namespace App\Modules\Contributions\Services;

use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributionValidation;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Support\TextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accepts peer-validated translations and mints corpus pairs. Ateso is
 * dialect-rich, so this accepts EVERY distinct variant that clears the gate —
 * not a single "winner" — each as its own dialect-tagged pair. A submission
 * clears the gate with enough validations and weighted approval (agree /
 * minor-fix / valid-variant count for, reject counts against). Convergent
 * duplicates of an already-accepted variant are folded in (no second pair).
 * Gold tasks never mint pairs. Idempotent and safe to call after every vote.
 */
class AcceptanceService
{
    public function __construct(private readonly RewardService $rewards) {}

    /**
     * @return Collection<int, CorpusPair> the pairs newly minted this run
     */
    public function evaluate(ContributionTask $task): Collection
    {
        if ($task->isGold()) {
            return collect();
        }

        return DB::transaction(function () use ($task) {
            /** @var ContributionTask|null $task */
            $task = ContributionTask::query()->lockForUpdate()->find($task->id);

            if (! $task || $task->status === ContributionTask::STATUS_CLOSED) {
                return collect();
            }

            $submissions = $task->submissions()->with(['validations.validator', 'user'])->get();
            if ($submissions->isEmpty()) {
                return collect();
            }

            $cfg = config('contributions.acceptance');
            $minValidations = (int) ($cfg['min_validations'] ?? 2);
            $threshold = (float) ($cfg['approval_threshold'] ?? 2.0);
            $convergenceBonus = (float) ($cfg['convergence_bonus'] ?? 10);

            // Variant keys already minted for this task, so we never double-mint.
            $acceptedKeys = $submissions
                ->where('status', ContributionSubmission::STATUS_ACCEPTED)
                ->map(fn ($s) => TextNormalizer::key((string) $s->normalized_text))
                ->filter()->unique()->values()->all();

            // Weighted approval for every still-open submission with enough votes.
            $approvals = [];
            $qualifying = $submissions
                ->filter(fn ($s) => $s->status === ContributionSubmission::STATUS_SUBMITTED)
                ->filter(fn ($s) => $s->validations->count() >= $minValidations)
                ->filter(function ($s) use (&$approvals, $threshold) {
                    $approvals[$s->id] = $this->approval($s);

                    return $approvals[$s->id] >= $threshold;
                });

            $newPairs = collect();

            foreach ($qualifying->groupBy(fn ($s) => TextNormalizer::key((string) $s->normalized_text)) as $key => $group) {
                $rep = $group->sortByDesc(fn ($s) => $approvals[$s->id])->first();

                // Already minted for this variant → fold convergent dupes in.
                if (in_array($key, $acceptedKeys, true)) {
                    $this->supersede($group);

                    continue;
                }

                $convergence = $group->count() - 1;
                $score = round(min(100.0, ($approvals[$rep->id] * 20) + ($convergence * $convergenceBonus)), 2);

                $newPairs->push($this->accept($task, $rep, $score, $submissions));
                $acceptedKeys[] = $key;

                // Convergent duplicates reinforce the variant but don't mint again.
                $this->supersede($group->reject(fn ($s) => $s->id === $rep->id));
            }

            return $newPairs;
        });
    }

    private function accept(ContributionTask $task, ContributionSubmission $rep, float $score, Collection $allSubmissions): CorpusPair
    {
        $rep->forceFill([
            'status' => ContributionSubmission::STATUS_ACCEPTED,
            'agreement_score' => $score,
        ])->save();

        ContributorProfile::query()->where('user_id', $rep->user_id)
            ->update(['submissions_accepted' => DB::raw('submissions_accepted + 1')]);

        [$enText, $atesoText] = $this->orientPair($task, $rep);

        $pair = new CorpusPair([
            'en_text' => $enText,
            'ateso_text' => $atesoText,
            'register' => $task->register,
            'region' => $task->region,
            'dialect' => $rep->dialect,
            'is_code_switched' => (bool) $rep->is_code_switched,
            'quality_score' => $score,
            'license_version' => config('contributions.license_version'),
            'provenance' => [
                'task_uuid' => $task->uuid,
                'accepted_user_id' => $rep->user_id,
                'dialect' => $rep->dialect,
                'contributor_ids' => $allSubmissions->pluck('user_id')->unique()->values()->all(),
                'validator_ids' => $rep->validations->pluck('validator_user_id')->unique()->values()->all(),
            ],
        ]);
        $pair->submission()->associate($rep);
        if ($task->source_type && $task->source_id) {
            $pair->source()->associate($task->source);
        }
        $pair->save();

        // Pay the translator and the validators who approved this variant.
        $this->rewards->rewardAcceptance($rep);

        return $pair;
    }

    /**
     * Weighted approval: approving verdicts add their weight, rejects subtract.
     */
    private function approval(ContributionSubmission $submission): float
    {
        $approval = 0.0;
        foreach ($submission->validations as $v) {
            if (in_array($v->verdict, ContributionValidation::APPROVING_VERDICTS, true)) {
                $approval += (float) $v->weight;
            } elseif ($v->verdict === ContributionValidation::VERDICT_REJECT) {
                $approval -= (float) $v->weight;
            }
        }

        return $approval;
    }

    /**
     * @param  Collection<int, ContributionSubmission>  $submissions
     */
    private function supersede(Collection $submissions): void
    {
        foreach ($submissions as $s) {
            if ($s->status === ContributionSubmission::STATUS_SUBMITTED) {
                $s->forceFill(['status' => ContributionSubmission::STATUS_SUPERSEDED])->save();
            }
        }
    }

    /**
     * @return array{0: string, 1: string} [en_text, ateso_text]
     */
    private function orientPair(ContributionTask $task, ContributionSubmission $rep): array
    {
        $source = (string) $task->prompt_text;
        $target = (string) $rep->normalized_text;

        if ($task->source_lang === 'en') {
            return [$source, $target];
        }

        return [$target, $source];
    }
}
