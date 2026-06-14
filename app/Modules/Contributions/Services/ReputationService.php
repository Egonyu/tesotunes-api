<?php

namespace App\Modules\Contributions\Services;

use App\Models\User;
use App\Modules\Contributions\Models\ContributorProfile;
use Illuminate\Support\Facades\DB;

/**
 * Owns contributor reputation: gold pass-rate, tier promotion, and the vote
 * weight a validator carries. Gold pass-rate is the trust signal — pass golds
 * consistently and you earn a higher tier (more reward, heavier votes,
 * tie-break rights); fail them and you stay capped.
 */
class ReputationService
{
    /**
     * Record the outcome of a gold-standard attempt and re-derive the tier.
     */
    public function recordGoldResult(User $user, bool $passed): ContributorProfile
    {
        return DB::transaction(function () use ($user, $passed) {
            $profile = ContributorProfile::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);

            $attempts = $profile->gold_attempts + 1;
            $goldPassed = $profile->gold_passed + ($passed ? 1 : 0);

            $profile->forceFill([
                'gold_attempts' => $attempts,
                'gold_passed' => $goldPassed,
                'gold_pass_rate' => $attempts > 0 ? round(($goldPassed / $attempts) * 100, 2) : 0,
            ]);
            $profile->tier = $this->deriveTier($profile);
            $profile->save();

            return $profile->refresh();
        });
    }

    /**
     * Tier from gold pass-rate, once a minimum number of golds have been seen.
     */
    public function deriveTier(ContributorProfile $profile): string
    {
        $tiers = config('contributions.tiers');

        if ($profile->gold_attempts < ($tiers['min_gold_attempts'] ?? 10)) {
            return ContributorProfile::TIER_NOVICE;
        }

        $rate = (float) $profile->gold_pass_rate;

        if ($rate >= ($tiers['reviewer_min_pass_rate'] ?? 95)) {
            return ContributorProfile::TIER_REVIEWER;
        }

        if ($rate >= ($tiers['trusted_min_pass_rate'] ?? 85)) {
            return ContributorProfile::TIER_TRUSTED;
        }

        return ContributorProfile::TIER_NOVICE;
    }

    /**
     * The validation vote weight for a user, from their current tier.
     */
    public function weightFor(User $user): float
    {
        $weights = config('contributions.validation_weights');
        $tier = ContributorProfile::query()->where('user_id', $user->id)->value('tier')
            ?? ContributorProfile::TIER_NOVICE;

        return (float) ($weights[$tier] ?? 1.0);
    }
}
