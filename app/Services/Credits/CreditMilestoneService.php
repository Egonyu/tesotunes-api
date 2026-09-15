<?php

namespace App\Services\Credits;

use App\Models\CreditMilestone;
use App\Models\CreditMilestoneClaim;
use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Credit goals: rewards for credits earned through activity.
 *
 * This replaces CreditService::getNextMilestone, which measured every credit
 * ever added — purchases and refunds included, since every addition is written
 * as `earned` — against a ladder of rewards nothing ever paid out. Buying
 * credits moved a member toward "Artist verification".
 *
 * Progress counts only the sources below: things a member did. It is an
 * allow-list on purpose. A new way to put credits into a wallet counts toward
 * nothing until someone decides it should, so a future purchase path cannot
 * quietly make a title buyable.
 */
class CreditMilestoneService
{
    /**
     * The payout source for a claimed goal. Deliberately not in the allow-list,
     * so claiming one goal never pushes a member toward the next.
     */
    public const PAYOUT_SOURCE = 'credit_milestone';

    /**
     * Ledger sources that represent activity.
     *
     * Rated activities (credit_rates) plus the award paths that predate the
     * rates table or pay from a pool. Excluded on purpose: purchases, refunds,
     * transfers, the welcome gift, and milestone payouts of either programme.
     */
    public const ACTIVITY_SOURCES = [
        CreditRate::REFERRAL_SIGNUP,
        CreditRate::DAILY_LOGIN,
        CreditRate::SONG_PLAY_COMPLETE,
        CreditRate::SOCIAL_LIKE,
        CreditRate::SOCIAL_SHARE,
        CreditRate::SOCIAL_COMMENT,
        CreditRate::SOCIAL_FOLLOW,
        CreditRate::PLAYLIST_CREATE,
        CreditRate::PROFILE_COMPLETE,
        CreditRate::CONTRIBUTION_TRANSLATION,
        CreditRate::CONTRIBUTION_VALIDATION,
        'listening',
        'social_interaction',
        'weekly_streak',
        'listen_earn',
        'poll_response',
    ];

    /**
     * Credits this account has earned through activity, all time.
     */
    public function activityCredits(User $user): int
    {
        return (int) CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', CreditTransaction::TYPE_EARNED)
            ->whereIn('source', self::ACTIVITY_SOURCES)
            ->sum('amount');
    }

    /**
     * Every active goal with this account's standing against it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function milestonesFor(User $user, ?int $earned = null): array
    {
        $earned ??= $this->activityCredits($user);

        $claimed = CreditMilestoneClaim::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('credit_milestone_id');

        return CreditMilestone::active()
            ->orderBy('credits_required')
            ->orderBy('sort_order')
            ->get()
            ->map(function (CreditMilestone $milestone) use ($earned, $claimed) {
                $claim = $claimed->get($milestone->id);
                $reached = $earned >= $milestone->credits_required;

                return [
                    'id' => $milestone->id,
                    'key' => $milestone->key,
                    'name' => $milestone->name,
                    'description' => (string) $milestone->description,
                    'credits_required' => $milestone->credits_required,
                    'remaining' => max(0, $milestone->credits_required - $earned),
                    'reward_type' => $milestone->reward_type,
                    'reward_value' => $milestone->reward_value,
                    'badge_name' => (string) $milestone->badge_name,
                    'badge_icon' => (string) $milestone->badge_icon,
                    'badge_tier' => $milestone->badge_tier,
                    'status' => match (true) {
                        $claim !== null => 'claimed',
                        $reached => 'claimable',
                        default => 'locked',
                    },
                    'claimed_at' => optional($claim?->claimed_at)->toIso8601String(),
                    'progress' => $milestone->credits_required > 0
                        ? min(100, (int) floor(($earned / $milestone->credits_required) * 100))
                        : 100,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The summary the /credits dashboard shows beside the balance.
     *
     * @return array{activity_credits:int, next:array<string, mixed>|null, claimable:int}
     */
    public function summaryFor(User $user): array
    {
        $earned = $this->activityCredits($user);
        $milestones = collect($this->milestonesFor($user, $earned));

        return [
            'activity_credits' => $earned,
            // The lowest rung not yet claimed: a claimable goal is shown before
            // the one after it, so a reward is never skipped past on screen.
            'next' => $milestones->first(fn (array $m) => $m['status'] !== 'claimed'),
            'claimable' => $milestones->where('status', 'claimable')->count(),
        ];
    }

    /**
     * Pay a goal out.
     *
     * The unique index on (user_id, credit_milestone_id) is the real guard; the
     * checks here only turn a refusal into a readable message. Credits and the
     * claim are written in one transaction, so neither can land without the
     * other.
     *
     * @throws \RuntimeException when the goal is not reached or already claimed
     */
    public function claim(User $user, CreditMilestone $milestone): CreditMilestoneClaim
    {
        $earned = $this->activityCredits($user);

        if ($earned < $milestone->credits_required) {
            throw new \RuntimeException(
                'This goal needs '.number_format($milestone->credits_required).' credits earned through activity — you have '.number_format($earned).'.'
            );
        }

        try {
            return DB::transaction(function () use ($user, $milestone) {
                $existing = CreditMilestoneClaim::query()
                    ->where('user_id', $user->id)
                    ->where('credit_milestone_id', $milestone->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new \RuntimeException('You have already claimed this goal.');
                }

                $pays = $milestone->reward_type === CreditMilestone::REWARD_CREDITS && $milestone->reward_value > 0;

                $claim = CreditMilestoneClaim::create([
                    'user_id' => $user->id,
                    'credit_milestone_id' => $milestone->id,
                    'credits_awarded' => $pays ? $milestone->reward_value : 0,
                    'claimed_at' => now(),
                ]);

                if ($pays) {
                    $user->addCredits(
                        $milestone->reward_value,
                        self::PAYOUT_SOURCE,
                        "Credit goal: {$milestone->name}",
                        ['reference' => 'credit_milestone:'.$milestone->id.':'.$user->id],
                    );
                }

                return $claim;
            });
        } catch (UniqueConstraintViolationException) {
            // Two claims raced past the check; the index let exactly one through.
            throw new \RuntimeException('You have already claimed this goal.');
        }
    }
}
