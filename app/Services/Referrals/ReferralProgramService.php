<?php

namespace App\Services\Referrals;

use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\ReferralMilestone;
use App\Models\ReferralMilestoneClaim;
use App\Models\User;
use App\Notifications\Concerns\BuildsFrontendUrls;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The referral programme.
 *
 * Everything here is derived from two facts already in the database:
 * users.referrer_id says who brought whom, and credit_transactions with a
 * referral source say what that paid. Nothing maintains a parallel counter,
 * because a counter that can disagree with the accounts it counts eventually
 * does — which is how referral_count on both users and user_referrals came to
 * be untrustworthy.
 *
 * The one thing that is stored is a milestone claim, because paying a reward
 * twice is the failure that has to be impossible.
 */
class ReferralProgramService
{
    use BuildsFrontendUrls;

    /**
     * How long an account can be quiet before a referral counts as churned.
     */
    private const CHURN_AFTER_DAYS = 30;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CHURNED = 'churned';

    public function referralLink(User $user): string
    {
        return $this->frontendUrl('register?ref='.urlencode((string) $user->referral_code));
    }

    /**
     * Accounts this user brought to the platform.
     */
    public function referredUsers(User $user): Collection
    {
        return User::query()
            ->where('referrer_id', $user->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Where a referred account stands.
     *
     * Deliberately observable rather than aspirational: `completed` means the
     * account went premium, which is the outcome the programme exists to
     * produce. Everything softer than that is activity.
     */
    public function statusFor(User $referred): string
    {
        if ($referred->is_premium) {
            return self::STATUS_COMPLETED;
        }

        $lastActive = $referred->last_activity_at ?? $referred->last_login_at;

        if (! $lastActive) {
            return self::STATUS_PENDING;
        }

        return $lastActive->diffInDays(now()) > self::CHURN_AFTER_DAYS
            ? self::STATUS_CHURNED
            : self::STATUS_ACTIVE;
    }

    /**
     * Credits this user has earned from referring people.
     *
     * Read off the ledger by source, so it always matches what was actually
     * paid rather than what a summary column remembers.
     */
    public function creditsEarned(User $user): int
    {
        return (int) CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', CreditTransaction::TYPE_EARNED)
            ->whereIn('source', [CreditRate::REFERRAL_SIGNUP, 'referral_milestone'])
            ->sum('amount');
    }

    /**
     * What a single referred account has earned its referrer.
     *
     * Matched on the ledger reference the signup award stamps. The
     * referenceable morph cannot be used: addCredits() always writes the
     * wallet there, so the `sourceable` the award is given never reaches a
     * row. Referrals paid before that reference existed return 0 rather than
     * a guessed figure.
     */
    public function creditsFromReferral(User $user, User $referred): int
    {
        return (int) CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', CreditTransaction::TYPE_EARNED)
            ->where('source', CreditRate::REFERRAL_SIGNUP)
            ->where('reference', 'referral:'.$referred->id)
            ->sum('amount');
    }

    /**
     * @return array{total:int,pending:int,active:int,completed:int,churned:int,total_credits_earned:int}
     */
    public function stats(User $user): array
    {
        $referred = $this->referredUsers($user);
        $byStatus = $referred->groupBy(fn (User $r) => $this->statusFor($r));

        return [
            'total' => $referred->count(),
            'pending' => $byStatus->get(self::STATUS_PENDING, collect())->count(),
            'active' => $byStatus->get(self::STATUS_ACTIVE, collect())->count(),
            'completed' => $byStatus->get(self::STATUS_COMPLETED, collect())->count(),
            'churned' => $byStatus->get(self::STATUS_CHURNED, collect())->count(),
            'total_credits_earned' => $this->creditsEarned($user),
        ];
    }

    /**
     * Every milestone with this account's standing against it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function milestonesFor(User $user): array
    {
        $count = User::where('referrer_id', $user->id)->count();

        $claimed = ReferralMilestoneClaim::where('user_id', $user->id)
            ->get()
            ->keyBy('referral_milestone_id');

        return ReferralMilestone::active()
            ->orderBy('referrals_required')
            ->orderBy('sort_order')
            ->get()
            ->map(function (ReferralMilestone $milestone) use ($count, $claimed) {
                $claim = $claimed->get($milestone->id);
                $reached = $count >= $milestone->referrals_required;

                return [
                    'id' => $milestone->id,
                    'name' => $milestone->name,
                    'description' => (string) $milestone->description,
                    'referrals_required' => $milestone->referrals_required,
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
                    'earned_at' => $reached ? optional($claim?->claimed_at)->toIso8601String() : null,
                    'claimed_at' => optional($claim?->claimed_at)->toIso8601String(),
                    'progress' => $milestone->referrals_required > 0
                        ? min(100, (int) round(($count / $milestone->referrals_required) * 100))
                        : 100,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The next rung, or null when the ladder is finished.
     *
     * @return array<string, mixed>|null
     */
    public function nextMilestone(User $user): ?array
    {
        $count = User::where('referrer_id', $user->id)->count();

        $claimedIds = ReferralMilestoneClaim::where('user_id', $user->id)
            ->pluck('referral_milestone_id');

        $next = ReferralMilestone::active()
            ->whereNotIn('id', $claimedIds)
            ->orderBy('referrals_required')
            ->first();

        if (! $next) {
            return null;
        }

        return [
            'name' => $next->name,
            'referrals_required' => $next->referrals_required,
            'current_count' => $count,
            'progress' => $next->referrals_required > 0
                ? min(100, (int) round(($count / $next->referrals_required) * 100))
                : 100,
            'reward_type' => $next->reward_type,
            'reward_value' => $next->reward_value,
        ];
    }

    /**
     * Pay a milestone out.
     *
     * The unique index on (user_id, referral_milestone_id) is the real guard;
     * the check below is only there to return a sensible message instead of a
     * constraint violation. Credits are awarded inside the same transaction as
     * the claim, so a failure cannot leave one without the other.
     *
     * @throws \RuntimeException when the milestone is not yet reached or already claimed
     */
    public function claim(User $user, ReferralMilestone $milestone): ReferralMilestoneClaim
    {
        $count = User::where('referrer_id', $user->id)->count();

        if ($count < $milestone->referrals_required) {
            throw new \RuntimeException(
                "This milestone needs {$milestone->referrals_required} referrals — you have {$count}."
            );
        }

        return DB::transaction(function () use ($user, $milestone) {
            $existing = ReferralMilestoneClaim::where('user_id', $user->id)
                ->where('referral_milestone_id', $milestone->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new \RuntimeException('You have already claimed this milestone.');
            }

            if ($milestone->reward_type === ReferralMilestone::REWARD_CREDITS && $milestone->reward_value > 0) {
                $user->addCredits(
                    $milestone->reward_value,
                    'referral_milestone',
                    "Referral milestone: {$milestone->name}",
                    ['milestone_id' => $milestone->id, 'milestone_key' => $milestone->key],
                );
            }

            return ReferralMilestoneClaim::create([
                'user_id' => $user->id,
                'referral_milestone_id' => $milestone->id,
                'credits_awarded' => $milestone->reward_type === ReferralMilestone::REWARD_CREDITS
                    ? $milestone->reward_value
                    : 0,
                'claimed_at' => now(),
            ]);
        });
    }

    /**
     * Top referrers, and where this account sits.
     *
     * Ranked on referrals actually made, counted from users.referrer_id.
     *
     * @return array<string, mixed>
     */
    public function leaderboard(User $viewer, int $limit = 20): array
    {
        $rows = User::query()
            ->select('users.id', 'users.name', 'users.username', 'users.avatar')
            ->selectRaw('(SELECT COUNT(*) FROM users AS referred WHERE referred.referrer_id = users.id AND referred.deleted_at IS NULL) AS referral_total')
            ->havingRaw('referral_total > 0')
            ->orderByDesc('referral_total')
            ->orderBy('users.id')
            ->limit($limit)
            ->get();

        $entries = $rows->values()->map(function (User $row, int $index) {
            return [
                'rank' => $index + 1,
                'user_id' => $row->id,
                'name' => $row->name,
                'username' => (string) $row->username,
                'avatar' => \App\Helpers\StorageHelper::avatarUrl($row->avatar, $row->name ?? 'User'),
                'referrals' => (int) $row->referral_total,
                'credits_earned' => $this->creditsEarned($row),
                'tier' => $this->tierFor((int) $row->referral_total),
            ];
        })->all();

        $viewerReferrals = User::where('referrer_id', $viewer->id)->count();
        $viewerRank = collect($entries)->firstWhere('user_id', $viewer->id)['rank'] ?? null;

        if ($viewerRank === null && $viewerReferrals > 0) {
            // Outside the visible page — count how many are strictly ahead.
            $ahead = User::query()
                ->selectRaw('COUNT(*) AS ahead')
                ->whereRaw('(SELECT COUNT(*) FROM users AS referred WHERE referred.referrer_id = users.id AND referred.deleted_at IS NULL) > ?', [$viewerReferrals])
                ->value('ahead');

            $viewerRank = ((int) $ahead) + 1;
        }

        $position = $viewerReferrals > 0 ? [
            'rank' => $viewerRank,
            'referrals' => $viewerReferrals,
            'credits_earned' => $this->creditsEarned($viewer),
            'tier' => $this->tierFor($viewerReferrals),
        ] : null;

        return [
            'leaderboard' => $entries,
            'current_user' => $position ? array_merge($position, [
                'user_id' => $viewer->id,
                'name' => $viewer->name,
            ]) : null,
            'user_position' => $position,
        ];
    }

    /**
     * Badge tier for a referral count. Mirrors the milestone ladder's shape
     * so a leaderboard tier and a milestone badge never disagree.
     */
    public function tierFor(int $referrals): string
    {
        return match (true) {
            $referrals >= 50 => ReferralMilestone::TIER_DIAMOND,
            $referrals >= 25 => ReferralMilestone::TIER_PLATINUM,
            $referrals >= 10 => ReferralMilestone::TIER_GOLD,
            $referrals >= 5 => ReferralMilestone::TIER_SILVER,
            default => ReferralMilestone::TIER_BRONZE,
        };
    }
}
