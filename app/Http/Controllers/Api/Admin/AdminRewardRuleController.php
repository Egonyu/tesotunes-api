<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credits\CreditObservabilityService;
use App\Services\Credits\RewardRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The operator surface for what the platform pays people to do.
 *
 * Rates, ceilings, cooldowns and campaign windows all live in credit_rates, so
 * marketing changes what an activity is worth here rather than in a deploy.
 */
class AdminRewardRuleController extends Controller
{
    public function __construct(
        private readonly RewardRuleService $rewards,
        private readonly CreditObservabilityService $observability,
    ) {}

    /**
     * GET /api/admin/reward-rules
     *
     * Every rate with what it has actually paid out, so a rule can be judged
     * against its cost rather than its intent.
     */
    public function index(): JsonResponse
    {
        $rates = $this->rewards->allRates();

        $spend = CreditTransaction::query()
            ->where('type', 'earned')
            ->whereIn('source', $rates->pluck('activity_type'))
            ->selectRaw('source, count(*) as awards, sum(amount) as credits, count(distinct user_id) as people')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        return response()->json([
            'data' => $rates->map(function (CreditRate $rate) use ($spend) {
                $stats = $spend->get($rate->activity_type);

                return [
                    'id' => $rate->id,
                    'activity_type' => $rate->activity_type,
                    'label' => $rate->label(),
                    'description' => $rate->description,
                    'credits_per_action' => (float) $rate->credits_per_action,
                    'daily_limit' => $rate->daily_limit !== null ? (float) $rate->daily_limit : null,
                    'cooldown_minutes' => $rate->cooldown_minutes,
                    'max_per_user_lifetime' => $rate->max_per_user_lifetime,
                    'starts_at' => $rate->starts_at?->toIso8601String(),
                    'ends_at' => $rate->ends_at?->toIso8601String(),
                    'is_active' => $rate->is_active,
                    'is_live' => $rate->isLive(),
                    'is_campaign' => $rate->isCampaign(),
                    'sort_order' => $rate->sort_order,
                    // What this rule has cost so far.
                    'awards' => (int) ($stats->awards ?? 0),
                    'credits_paid' => (float) ($stats->credits ?? 0),
                    'people_paid' => (int) ($stats->people ?? 0),
                ];
            })->values(),
        ]);
    }

    /** POST /api/admin/reward-rules */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $rate = CreditRate::create($validated);

        return response()->json(['data' => $rate, 'message' => 'Reward rule created.'], 201);
    }

    /** PUT /api/admin/reward-rules/{rate} */
    public function update(Request $request, CreditRate $rewardRule): JsonResponse
    {
        $validated = $request->validate($this->rules($rewardRule->id));

        $rewardRule->update($validated);

        return response()->json(['data' => $rewardRule->fresh(), 'message' => 'Reward rule updated.']);
    }

    /**
     * DELETE /api/admin/reward-rules/{rate}
     *
     * Deactivates rather than deletes. A rule that has paid people is part of
     * the ledger's history, and removing the row would orphan every transaction
     * that cites it.
     */
    public function destroy(CreditRate $rewardRule): JsonResponse
    {
        $rewardRule->update(['is_active' => false]);

        return response()->json(['message' => 'Reward rule switched off.']);
    }

    /**
     * GET /api/admin/reward-rules/referrals
     *
     * How the referral programme is actually doing.
     */
    public function referrals(): JsonResponse
    {
        $signups = User::query()->whereNotNull('referrer_id')->count();

        $paidOut = (float) CreditTransaction::query()
            ->where('type', 'earned')
            ->whereIn('source', [CreditRate::REFERRAL_SIGNUP, CreditRate::REFERRAL_WELCOME])
            ->sum('amount');

        $topReferrers = User::query()
            ->whereNotNull('referrer_id')
            ->selectRaw('referrer_id, count(*) as referred')
            ->groupBy('referrer_id')
            ->orderByDesc('referred')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $referrer = User::find($row->referrer_id);

                return [
                    'user_id' => $row->referrer_id,
                    'name' => $referrer?->name,
                    'referred' => (int) $row->referred,
                ];
            });

        return response()->json([
            'data' => [
                'referred_signups' => $signups,
                'credits_paid_out' => $paidOut,
                'accounts_with_codes' => User::query()->whereNotNull('referral_code')->count(),
                'total_accounts' => User::query()->count(),
                'top_referrers' => $topReferrers,
                'signup_rate' => $this->rewards->rateFor(CreditRate::REFERRAL_SIGNUP)?->credits_per_action,
                'welcome_rate' => $this->rewards->rateFor(CreditRate::REFERRAL_WELCOME)?->credits_per_action,
            ],
        ]);
    }

    /**
     * GET /api/admin/reward-rules/issues
     *
     * Rewards that were meant to be paid and were not.
     */
    public function issues(Request $request): JsonResponse
    {
        return response()->json([
            'summary' => $this->observability->summary(),
            'data' => $this->observability->listIssues($request->only(['status', 'type', 'source', 'severity', 'unresolved'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'activity_type' => [
                'required', 'string', 'max:50',
                Rule::unique('credit_rates', 'activity_type')->ignore($ignoreId),
            ],
            'display_name' => 'nullable|string|max:255',
            'credits_per_action' => 'required|numeric|min:0|max:1000000',
            'daily_limit' => 'nullable|numeric|min:0|max:1000000',
            'cooldown_minutes' => 'nullable|integer|min:0|max:525600',
            'max_per_user_lifetime' => 'nullable|integer|min:1|max:100000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000',
        ];
    }
}
