<?php

namespace App\Services\Credits;

use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The one way the platform pays somebody for doing something.
 *
 * Every rewardable activity is priced and regulated by a row in credit_rates,
 * so what the platform pays is an operator decision rather than a constant
 * buried in a service. Rates carry a daily ceiling, a cooldown, a lifetime cap
 * and an optional campaign window — a marketing push is just a rate that pays
 * more between two dates.
 *
 * Awards go through {@see CreditObservabilityService}, so a reward that fails
 * leaves a CreditIssue behind rather than vanishing.
 */
class RewardRuleService
{
    public function __construct(
        private readonly CreditObservabilityService $observability,
    ) {}

    /** The live rate for an activity, or null if it pays nothing right now. */
    public function rateFor(string $activity): ?CreditRate
    {
        return CreditRate::query()->live()->forActivity($activity)->first();
    }

    /** Every rate an operator can see, live or not. */
    public function allRates(): Collection
    {
        return CreditRate::query()->ordered()->get();
    }

    /**
     * Pay a user for an activity, applying every rule attached to it.
     *
     * @param  array{multiplier?: float, sourceable?: Model|null, description?: string, metadata?: array<string, mixed>}  $options
     */
    public function award(User $user, string $activity, array $options = []): RewardOutcome
    {
        $rate = CreditRate::query()->forActivity($activity)->first();

        if (! $rate) {
            return RewardOutcome::notRewarded($activity);
        }

        if (! $rate->isLive()) {
            return RewardOutcome::inactive($activity);
        }

        if ($rate->max_per_user_lifetime !== null) {
            $taken = $this->timesAwarded($user, $activity);

            if ($taken >= $rate->max_per_user_lifetime) {
                return RewardOutcome::lifetimeCapReached($rate->max_per_user_lifetime);
            }
        }

        if ($rate->cooldown_minutes) {
            $waitLeft = $this->cooldownRemaining($user, $activity, $rate->cooldown_minutes);

            if ($waitLeft > 0) {
                return RewardOutcome::cooldown($waitLeft);
            }
        }

        $credits = round((float) $rate->credits_per_action * (float) ($options['multiplier'] ?? 1.0), 2);

        if ($rate->daily_limit !== null) {
            $earnedToday = $this->earnedToday($user, $activity);
            $headroom = round((float) $rate->daily_limit - $earnedToday, 2);

            if ($headroom <= 0) {
                return RewardOutcome::dailyLimitReached((float) $rate->daily_limit);
            }

            // Pay the part that still fits rather than refusing the whole
            // award: a user one credit under their ceiling should get that
            // credit, not nothing.
            $credits = min($credits, $headroom);
        }

        if ($credits <= 0) {
            return RewardOutcome::notRewarded($activity);
        }

        $transaction = $this->observability->award(
            $user,
            $credits,
            $activity,
            $options['description'] ?? $rate->label(),
            [
                'sourceable' => $options['sourceable'] ?? null,
                'metadata' => $options['metadata'] ?? [],
            ],
        );

        if (! $transaction) {
            // award() has already raised the CreditIssue.
            return RewardOutcome::failed("The award for '{$activity}' did not land.");
        }

        return RewardOutcome::awarded($transaction, $credits);
    }

    /** Credits this user has earned from this activity since midnight. */
    public function earnedToday(User $user, string $activity): float
    {
        return (float) CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('source', $activity)
            ->where('type', 'earned')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('amount');
    }

    /** How many times this user has ever been paid for this activity. */
    public function timesAwarded(User $user, string $activity): int
    {
        return CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('source', $activity)
            ->where('type', 'earned')
            ->count();
    }

    /** Whole minutes still to wait, or 0 if the activity is available now. */
    public function cooldownRemaining(User $user, string $activity, int $cooldownMinutes): int
    {
        $last = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('source', $activity)
            ->where('type', 'earned')
            ->latest('created_at')
            ->value('created_at');

        if (! $last) {
            return 0;
        }

        $availableAt = $last->copy()->addMinutes($cooldownMinutes);

        return $availableAt->isFuture() ? (int) ceil(now()->diffInMinutes($availableAt, true)) : 0;
    }

    /**
     * What a given user can still earn today, per activity — the honest version
     * of an "earning opportunities" list, which previously advertised rates
     * that no longer existed and limits nothing enforced.
     *
     * @return array<int, array<string, mixed>>
     */
    public function opportunitiesFor(User $user): array
    {
        return CreditRate::query()->live()->ordered()->get()->map(function (CreditRate $rate) use ($user) {
            $earnedToday = $this->earnedToday($user, $rate->activity_type);

            return [
                'activity_type' => $rate->activity_type,
                'label' => $rate->label(),
                'description' => $rate->description,
                'credits_per_action' => (float) $rate->credits_per_action,
                'daily_limit' => $rate->daily_limit !== null ? (float) $rate->daily_limit : null,
                'earned_today' => $earnedToday,
                'remaining_today' => $rate->daily_limit !== null
                    ? max(0, round((float) $rate->daily_limit - $earnedToday, 2))
                    : null,
                'cooldown_minutes' => $rate->cooldown_minutes,
                'available_in_minutes' => $rate->cooldown_minutes
                    ? $this->cooldownRemaining($user, $rate->activity_type, $rate->cooldown_minutes)
                    : 0,
                'is_campaign' => $rate->isCampaign(),
                'ends_at' => $rate->ends_at?->toIso8601String(),
            ];
        })->all();
    }
}
