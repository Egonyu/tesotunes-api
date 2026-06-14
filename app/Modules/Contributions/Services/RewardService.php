<?php

namespace App\Modules\Contributions\Services;

use App\Models\Commerce\Settlement;
use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Services\Commerce\SettlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Pays accepted work in credits, riding the unified settlement ledger
 * (vertical: contributions). A translation pays the per-pair rate; each
 * agreeing validator pays a fraction of it. Spend is bounded by a daily pool
 * and a per-contributor daily cap, so reward is "airtime money", never an
 * open-ended faucet. Idempotent — the settlement's unique (source, beneficiary,
 * kind) key means re-evaluation never double-pays.
 */
class RewardService
{
    public function __construct(private readonly SettlementService $settlements) {}

    /**
     * Reward the accepted translation and the validators who approved it.
     * Called from AcceptanceService once a corpus pair is minted.
     */
    public function rewardAcceptance(ContributionSubmission $winner): void
    {
        if (! $winner->settled) {
            $this->rewardTranslation($winner);
        }

        foreach ($winner->validations as $validation) {
            if (in_array($validation->verdict, ['agree', 'minor_fix'], true)) {
                $this->rewardValidation($validation->validator, $validation, $winner);
            }
        }
    }

    private function rewardTranslation(ContributionSubmission $winner): void
    {
        $base = (int) config('contributions.rewards.per_pair_ugx', 200);
        $amount = $this->grant($winner->user, $winner, 'translation_accepted', $base, 'contribution_translation', [
            'submission_uuid' => $winner->uuid,
        ]);

        $winner->forceFill(['settled' => true, 'settled_at' => now()])->save();

        if ($amount > 0) {
            $this->bumpEarned($winner->user_id, $amount);
        }
    }

    private function rewardValidation(?User $validator, Model $validation, ContributionSubmission $winner): void
    {
        if (! $validator) {
            return;
        }

        $base = (int) config('contributions.rewards.per_pair_ugx', 200);
        $pct = (float) config('contributions.rewards.validation_pct', 0.5);
        $nominal = (int) round($base * $pct);

        $amount = $this->grant($validator, $validation, 'validation_accepted', $nominal, 'contribution_validation', [
            'submission_uuid' => $winner->uuid,
        ]);

        if ($amount > 0) {
            $this->bumpEarned($validator->id, $amount);
        }
    }

    /**
     * Compute the bounded credit amount, record + clear a settlement, and credit
     * the wallet — once. Returns the credits granted (0 if capped/pool-dry or
     * already settled).
     */
    private function grant(User $user, Model $source, string $kind, int $nominal, string $creditSource, array $metadata): int
    {
        return DB::transaction(function () use ($user, $source, $kind, $nominal, $creditSource, $metadata) {
            // Idempotency: never pay twice for the same (source, beneficiary, kind).
            $exists = Settlement::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->where('beneficiary_user_id', $user->id)
                ->where('kind', $kind)
                ->exists();

            if ($exists) {
                return 0;
            }

            $amount = $this->boundedAmount($user, $nominal);
            if ($amount <= 0) {
                return 0;
            }

            $settlement = $this->settlements->record(
                beneficiary: $user,
                source: $source,
                vertical: Settlement::VERTICAL_CONTRIBUTIONS,
                kind: $kind,
                amounts: ['gross_credits' => $amount, 'fee_credits' => 0],
                holdUntil: null,
                metadata: $metadata,
            );

            // Acceptance is the clearance authority — clear immediately so the
            // earning is withdrawable via the KYC-gated payout flow.
            $this->settlements->clear($settlement);

            $user->addCredits($amount, $creditSource, 'Ateso corpus contribution accepted', $metadata);

            return $amount;
        });
    }

    /**
     * Apply the tier multiplier, the per-contributor daily cap, and the shared
     * daily pool ceiling.
     */
    private function boundedAmount(User $user, int $nominal): int
    {
        $tier = ContributorProfile::query()->where('user_id', $user->id)->value('tier')
            ?? ContributorProfile::TIER_NOVICE;

        $multiplier = in_array($tier, [ContributorProfile::TIER_TRUSTED, ContributorProfile::TIER_REVIEWER], true)
            ? (float) config('contributions.rewards.trusted_multiplier', 1.3)
            : 1.0;

        $amount = (int) round($nominal * $multiplier);

        // Per-contributor daily cap (number of rewarded items today).
        $cap = (int) config('contributions.rewards.per_contributor_daily_cap', 20);
        $todayCount = Settlement::query()
            ->where('vertical', Settlement::VERTICAL_CONTRIBUTIONS)
            ->where('beneficiary_user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();
        if ($todayCount >= $cap) {
            return 0;
        }

        // Shared daily pool ceiling — clamp the grant to what's left.
        $pool = (int) config('contributions.rewards.daily_pool_ugx', 50000);
        $grantedToday = (int) Settlement::query()
            ->where('vertical', Settlement::VERTICAL_CONTRIBUTIONS)
            ->whereDate('created_at', today())
            ->sum('gross_credits');

        $remaining = $pool - $grantedToday;
        if ($remaining <= 0) {
            return 0;
        }

        return min($amount, $remaining);
    }

    private function bumpEarned(int $userId, int $amount): void
    {
        ContributorProfile::query()->where('user_id', $userId)
            ->update(['credits_earned_total' => DB::raw("credits_earned_total + {$amount}")]);
    }
}
