<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\CreditRate;
use App\Models\ReferralMilestone;
use App\Models\ReferralMilestoneClaim;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\Referrals\ReferralProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member-facing referral programme.
 *
 * These endpoints did not exist. The four screens under /referrals were built
 * against them and shipped calling nothing — every request 404'd — which is
 * why the programme has never been visible to the people it is meant to
 * motivate, even though signup rewards do pay out.
 */
class ReferralController extends Controller
{
    public function __construct(private readonly ReferralProgramService $referrals) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $recent = $this->referrals->referredUsers($user)
            ->take(5)
            ->map(fn (User $referred) => [
                'id' => (string) $referred->id,
                'user' => $this->serializeReferredUser($referred),
                'status' => $this->referrals->statusFor($referred),
                'joined_at' => optional($referred->created_at)->toIso8601String(),
                'credits_earned' => $this->referrals->creditsFromReferral($user, $referred),
            ])
            ->values()
            ->all();

        $claimable = collect($this->referrals->milestonesFor($user))
            ->where('status', 'claimable')
            ->count();

        return response()->json([
            'data' => [
                'referral_code' => (string) $user->referral_code,
                'referral_link' => $this->referrals->referralLink($user),
                'stats' => $this->referrals->stats($user),
                'recent_referrals' => $recent,
                'claimable_rewards' => $claimable,
                'next_milestone' => $this->referrals->nextMilestone($user),
                /**
                 * What the programme actually pays right now, read from
                 * credit_rates. The screen used to state "50 credits" in
                 * fixed copy, which is only ever right by coincidence — the
                 * rate is operator-editable and can sit inside a time-limited
                 * window. Advertising a figure the platform will not honour is
                 * the same failure as the credit offers on /credits.
                 */
                'reward_rates' => [
                    'referrer_credits' => $this->liveRate(CreditRate::REFERRAL_SIGNUP),
                    'joiner_credits' => $this->liveRate(CreditRate::REFERRAL_WELCOME),
                ],
            ],
        ]);
    }

    /**
     * What an activity pays today, or 0 when no live rate is configured.
     */
    private function liveRate(string $activity): int
    {
        $rate = CreditRate::query()->forActivity($activity)->first();

        return $rate && $rate->isLive() ? (int) $rate->credits_per_action : 0;
    }

    public function code(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'referral_code' => (string) $user->referral_code,
                'referral_link' => $this->referrals->referralLink($user),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $page = max(1, (int) $request->integer('page', 1));
        $statusFilter = trim((string) $request->input('status', ''));

        $all = $this->referrals->referredUsers($user)
            ->map(fn (User $referred) => [
                'id' => (string) $referred->id,
                'user' => $this->serializeReferredUser($referred),
                'status' => $this->referrals->statusFor($referred),
                'active_days' => $referred->created_at
                    ? (int) $referred->created_at->diffInDays(now())
                    : 0,
                'last_active_at' => optional($referred->last_activity_at ?? $referred->last_login_at)->toIso8601String(),
                'credits_earned' => $this->referrals->creditsFromReferral($user, $referred),
                'joined_at' => optional($referred->created_at)->toIso8601String(),
                'subscription_tier' => $referred->is_premium ? 'premium' : 'free',
            ]);

        $filtered = $statusFilter !== ''
            ? $all->where('status', $statusFilter)->values()
            : $all;

        $total = $filtered->count();

        return response()->json([
            'data' => [
                'referrals' => $filtered->forPage($page, $perPage)->values()->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
                'stats' => array_merge(
                    $this->referrals->stats($user),
                    ['total_credits' => $this->referrals->creditsEarned($user)],
                ),
            ],
        ]);
    }

    public function rewards(Request $request): JsonResponse
    {
        $user = $request->user();
        $milestones = $this->referrals->milestonesFor($user);
        $current = User::where('referrer_id', $user->id)->count();

        $badges = collect($milestones)
            ->where('status', 'claimed')
            ->map(fn (array $milestone) => [
                'id' => $milestone['id'],
                'name' => $milestone['badge_name'] ?: $milestone['name'],
                'icon' => $milestone['badge_icon'],
                'tier' => $milestone['badge_tier'],
                'earned_at' => $milestone['claimed_at'],
            ])
            ->values()
            ->all();

        $fromMilestones = (int) ReferralMilestoneClaim::where('user_id', $user->id)->sum('credits_awarded');

        return response()->json([
            'data' => [
                'milestones' => $milestones,
                'badges' => $badges,
                'current_referrals' => $current,
                'total_credits_from_milestones' => $fromMilestones,
                'stats' => [
                    'total_credits_earned' => $this->referrals->creditsEarned($user),
                    'earned_rewards' => count($badges),
                    'claimable_rewards' => collect($milestones)->where('status', 'claimable')->count(),
                    'current_referrals' => $current,
                ],
            ],
        ]);
    }

    public function claim(Request $request, int $milestone): JsonResponse
    {
        $target = ReferralMilestone::active()->findOrFail($milestone);

        try {
            $claim = $this->referrals->claim($request->user(), $target);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "{$target->name} claimed. {$claim->credits_awarded} credits added.",
            'data' => [
                'milestone_id' => $target->id,
                'credits_awarded' => $claim->credits_awarded,
                'claimed_at' => $claim->claimed_at->toIso8601String(),
            ],
        ]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 20), 100));

        return response()->json([
            'data' => array_merge(
                $this->referrals->leaderboard($request->user(), $limit),
                ['period' => (string) $request->input('period', 'all_time')],
            ),
        ]);
    }

    /**
     * Check a code before signup, so the register screen can say whose
     * invitation this is rather than failing silently after the fact.
     */
    public function validateCode(string $code): JsonResponse
    {
        $referrer = User::where('referral_code', $code)->first();

        return response()->json([
            'data' => [
                'valid' => $referrer !== null,
                'referrer_name' => $referrer?->name,
            ],
        ]);
    }

    public function trackShare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'required|string|max:50',
        ]);

        // Recorded as an activity rather than a counter so the programme's
        // reach can be read off the same log as everything else.
        ActivityService::log(
            $request->user(),
            'referral_shared',
            $request->user(),
            [
                'platform' => $validated['platform'],
                'referral_code' => $request->user()->referral_code,
            ],
        );

        return response()->json(['message' => 'Share recorded.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReferredUser(User $referred): array
    {
        return [
            'id' => $referred->id,
            'name' => $referred->name,
            'username' => (string) $referred->username,
            // Deliberately not the email: a referrer has no business seeing
            // the address of someone who used their link.
            'avatar' => StorageHelper::avatarUrl($referred->avatar, $referred->name ?? 'User'),
        ];
    }
}
